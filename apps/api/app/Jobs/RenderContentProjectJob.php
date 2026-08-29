<?php

namespace App\Jobs;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStatus;
use App\Exceptions\Media\RenderFailedException;
use App\Exceptions\Media\TextDoesNotFitException;
use App\Models\ContentProject;
use App\Models\RenderJob;
use App\Services\Media\VideoRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the FFmpeg render on the queue.
 *
 * Owns the state machine around one attempt: rendering → rendered | failed,
 * plus throttled progress. FFmpeg must never run inside an HTTP request, so
 * this is the only place VideoRenderer::render() is called in production.
 */
class RenderContentProjectJob implements ShouldQueue
{
    use Queueable;

    /**
     * One retry only. A render that failed for a real reason (bad audio,
     * unfittable text) will fail again, and each attempt costs real minutes.
     */
    public int $tries = 2;

    /** Progress bookkeeping, so we do not write to the DB per frame. */
    private int $lastPercent = -1;

    private float $lastWriteAt = 0.0;

    public function __construct(
        public readonly int $projectId,
        public readonly int $renderJobId,
    ) {
        $this->onQueue('media');
    }

    public function handle(VideoRenderer $renderer): void
    {
        $project = ContentProject::with(['topic', 'speaker'])->find($this->projectId);
        $job = RenderJob::find($this->renderJobId);

        if ($project === null || $job === null) {
            return;
        }

        // A retry of an attempt that already finished must not re-run it.
        if (! $job->status->isInFlight()) {
            return;
        }

        $job->forceFill([
            'status' => RenderJobStatus::Running,
            'started_at' => now(),
        ])->save();

        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();

        try {
            $result = $renderer->render($project, function (float $fraction) use ($job): void {
                $this->reportProgress($job, $fraction);
            });

            $job->forceFill([
                'status' => RenderJobStatus::Succeeded,
                'progress_percent' => 100,
                'finished_at' => now(),
                'output_path' => $result['output_path'],
                'output_size' => $result['size'],
                'output_duration' => $result['duration'],
                'ffmpeg_exit_code' => $result['exit_code'],
                'ffmpeg_log' => $result['log'],
            ])->save();

            $project->forceFill([
                'render_status' => RenderStatus::Rendered,
                'output_path' => $result['output_path'],
                'output_size' => $result['size'],
                'output_duration' => $result['duration'],
                'rendered_at' => now(),
                'render_error' => null,
            ])->save();
        } catch (TextDoesNotFitException|RenderFailedException $e) {
            // Expected, explainable failures — the message is user-facing.
            $this->fail($project, $job, $e->getMessage(), $e);
        } catch (Throwable $e) {
            // Anything else: keep the detail in the log, not in the response.
            Log::error('Render failed unexpectedly', [
                'project_id' => $project->id,
                'render_job_id' => $job->id,
                'exception' => $e,
            ]);

            $this->fail($project, $job, 'Rendering failed unexpectedly. Please try again.', $e);
        }
    }

    /**
     * Mark the queued attempt failed if the job itself dies (timeout, worker
     * loss) so a project is never left stuck on "rendering".
     */
    public function failed(?Throwable $e): void
    {
        $job = RenderJob::find($this->renderJobId);
        $project = ContentProject::find($this->projectId);

        if ($job !== null && $job->status->isInFlight()) {
            $job->forceFill([
                'status' => RenderJobStatus::Failed,
                'finished_at' => now(),
                'error_message' => 'The render did not complete. It may have exceeded the time limit.',
            ])->save();
        }

        if ($project !== null && $project->render_status->isInFlight()) {
            $project->forceFill([
                'render_status' => RenderStatus::Failed,
                'render_error' => 'The render did not complete. It may have exceeded the time limit.',
            ])->save();
        }
    }

    private function fail(ContentProject $project, RenderJob $job, string $message, Throwable $e): void
    {
        $job->forceFill([
            'status' => RenderJobStatus::Failed,
            'finished_at' => now(),
            'error_message' => $message,
            'ffmpeg_log' => $job->ffmpeg_log ?? substr($e->getMessage(), 0, 2000),
        ])->save();

        $project->forceFill([
            'render_status' => RenderStatus::Failed,
            'render_error' => $message,
        ])->save();
    }

    /**
     * Persist progress only when it moved enough, or enough time passed.
     * FFmpeg reports many times per second; the database does not need that.
     */
    private function reportProgress(RenderJob $job, float $fraction): void
    {
        $percent = (int) min(99, floor($fraction * 100));
        $now = microtime(true);

        $movedEnough = $percent >= $this->lastPercent + (int) config('media.progress.min_percent_step');
        $waitedEnough = ($now - $this->lastWriteAt) >= (float) config('media.progress.min_interval_seconds');

        if (! $movedEnough && ! $waitedEnough) {
            return;
        }

        if ($percent <= $this->lastPercent) {
            return;
        }

        $this->lastPercent = $percent;
        $this->lastWriteAt = $now;

        $job->forceFill(['progress_percent' => $percent])->save();
    }
}
