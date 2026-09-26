<?php

namespace App\Services\Media;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStatus;
use App\Exceptions\Media\TextDoesNotFitException;
use App\Jobs\RenderContentProjectJob;
use App\Models\ContentProject;
use App\Models\RenderJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Queues a render, with every precondition checked in one place.
 *
 * Extracted because there are two callers now: the single-project button, and
 * the bulk "finish outdated projects" action. Writing the second one afresh
 * would have meant two implementations of what "ready to render" means, and
 * they would not have stayed the same — the bulk path would have skipped the
 * layout check, or forgotten the transaction that stops two clicks enqueueing
 * two attempts, and the difference would only show up as a job that fails
 * twenty minutes later with nowhere obvious to look.
 *
 * Every refusal is a code plus a sentence, because the bulk caller has to
 * summarise fifty of them and the single caller has to show one.
 */
class RenderDispatcher
{
    public function __construct(
        private readonly VideoRenderer $renderer,
    ) {}

    /**
     * Why this project cannot be rendered right now, or null if it can.
     *
     * Ordered by what the user would fix first: missing media before missing
     * text, both before a layout that does not fit.
     *
     * @return array{code: string, field: string, message: string}|null
     */
    public function blocker(ContentProject $project): ?array
    {
        if (! $project->hasRequiredMedia()) {
            return [
                'code' => 'missing_media',
                'field' => 'media',
                'message' => 'Upload both the lecture audio and a background image before rendering.',
            ];
        }

        if (! $project->hasRequiredText()) {
            return [
                'code' => 'missing_text',
                'field' => 'primary_title',
                'message' => 'Enter a primary title before rendering.',
            ];
        }

        // The columns can be set while the files are gone — a deploy that
        // replaced storage/, or a prune that ran after the window closed.
        $missing = $this->missingSource($project);

        if ($missing !== null) {
            return $missing;
        }

        try {
            $this->renderer->resolveLayout($project->loadMissing(['topic', 'speaker']));
        } catch (TextDoesNotFitException $e) {
            return [
                'code' => 'text_does_not_fit',
                'field' => $e->element,
                'message' => $e->getMessage(),
            ];
        }

        return null;
    }

    /**
     * Claim the render and queue it.
     *
     * Returns null when something else already holds the project — a second
     * click, or a bulk run overlapping a single one. The claim is a locked
     * transaction rather than a status check followed by a write, because
     * those two statements are exactly far enough apart for both callers to
     * pass.
     *
     * @param  array<string, mixed>|null  $postActions  snapshotted onto the attempt
     */
    public function dispatch(ContentProject $project, ?array $postActions = null): ?RenderJob
    {
        $renderJob = DB::transaction(function () use ($project, $postActions): ?RenderJob {
            $fresh = ContentProject::whereKey($project->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->render_status->isInFlight()) {
                return null;
            }

            $fresh->forceFill([
                'render_status' => RenderStatus::Queued,
                'render_error' => null,
            ])->save();

            // Each attempt is a new row; history is never overwritten.
            return $fresh->renderJobs()->create([
                'status' => RenderJobStatus::Queued,
                'progress_percent' => 0,
                'post_actions' => $postActions,
            ]);
        });

        if ($renderJob !== null) {
            RenderContentProjectJob::dispatch($project->id, $renderJob->id);
        }

        return $renderJob;
    }

    /**
     * Stop the render this project is running, or has queued.
     *
     * Two different acts wearing one name, and the difference is worth being
     * precise about because one of them is instant and the other is not:
     *
     *  - **Queued.** Nothing has started. The attempt is closed out here and
     *    now, and a worker that later picks the job up finds it already
     *    finished and does nothing.
     *
     *  - **Running.** FFmpeg is encoding in another process, possibly on
     *    another host, and cannot be reached from a web request. All this can
     *    do is leave a note the worker is looking for. It reads it within a
     *    couple of seconds and stops, so the caller is told "stopping" rather
     *    than "stopped" — claiming otherwise would have the studio show a
     *    finished render while FFmpeg was still writing frames.
     *
     * `cancel_requested_at` is written in both cases, not just the second. A
     * cancellation landing in the instant a worker is starting the encode
     * would otherwise write Cancelled just before the worker wrote Running,
     * and the render nobody wanted would run to completion. The flag is what
     * the worker re-reads, so whoever wins that race the encode still stops.
     *
     * @return array{outcome: 'cancelled'|'stopping'|'nothing_to_cancel', message: string}
     */
    public function cancel(ContentProject $project): array
    {
        return DB::transaction(function () use ($project): array {
            // The attempt row, not the project, is what both sides write —
            // locking it is what serialises this against a worker claiming
            // the same attempt.
            $job = $project->renderJobs()->latest('id')->lockForUpdate()->first();

            if ($job === null || ! $job->status->isInFlight()) {
                return [
                    'outcome' => 'nothing_to_cancel',
                    'message' => 'There is no render in progress for this project.',
                ];
            }

            $wasQueued = $job->status === RenderJobStatus::Queued;

            $job->forceFill([
                'cancel_requested_at' => now(),
                ...$wasQueued ? [
                    'status' => RenderJobStatus::Cancelled,
                    'finished_at' => now(),
                ] : [],
            ])->save();

            if ($wasQueued) {
                $project->forceFill([
                    'render_status' => RenderStatus::Cancelled,
                    'render_error' => null,
                ])->save();

                return [
                    'outcome' => 'cancelled',
                    'message' => 'Render cancelled. You can change the project and render again.',
                ];
            }

            // Left on Rendering on purpose. The encode is still running for
            // another second or two, and the worker owns the transition to
            // Cancelled — writing it here would describe a state that is not
            // true yet.
            return [
                'outcome' => 'stopping',
                'message' => 'Stopping the render. This takes a moment while FFmpeg shuts down.',
            ];
        });
    }

    /**
     * Which source file the database claims exists but the disk does not.
     *
     * Reported against the specific field rather than a generic "media", so
     * the studio can put the error next to the upload that needs redoing —
     * "one of your two files is gone" is not something anybody can act on.
     *
     * @return array{code: string, field: string, message: string}|null
     */
    private function missingSource(ContentProject $project): ?array
    {
        $disk = Storage::disk('local');

        $sources = [
            'audio' => [$project->source_audio_path, 'lecture recording'],
            'background' => [$project->background_image_path, 'background image'],
        ];

        foreach ($sources as $field => [$path, $noun]) {
            if (filled($path) && ! $disk->exists($path)) {
                return [
                    'code' => 'missing_source_file',
                    'field' => $field,
                    'message' => "The {$noun} is no longer on the server. Please upload it again.",
                ];
            }
        }

        return null;
    }
}
