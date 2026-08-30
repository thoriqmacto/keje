<?php

namespace App\Jobs;

use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Services\Google\GoogleNotConnectedException;
use App\Services\Google\YouTubePlaylistAssigner;
use App\Services\Google\YouTubeService;
use App\Services\Media\MediaRetention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Uploads the rendered MP4 to YouTube.
 *
 * Independent of the render and Drive pipelines, and idempotent: once a video
 * id exists this refuses to run again, because a duplicate upload creates a
 * second real video on the channel.
 */
class UploadVideoToYouTubeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('media');
    }

    public function handle(
        YouTubeService $youtube,
        MediaRetention $retention,
        YouTubePlaylistAssigner $playlists,
    ): void {
        $project = ContentProject::with(['topic', 'speaker'])->find($this->projectId);

        if ($project === null) {
            return;
        }

        // Hard idempotency guard. A retry must never publish a second copy.
        if ($project->youtube_status->hasVideo() && filled($project->youtube_video_id)) {
            return;
        }

        if (blank($project->output_path)) {
            $this->fail($project, 'There is no rendered video to upload.');

            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($project->output_path)) {
            $this->fail($project, 'The rendered video is no longer available.');

            return;
        }

        $project->forceFill([
            'youtube_status' => YouTubeStatus::Uploading,
            'youtube_error' => null,
        ])->save();

        try {
            $result = $youtube->upload(
                user: $project->user,
                project: $project,
                absolutePath: $disk->path($project->output_path),
            );

            $project->forceFill([
                'youtube_status' => $result['publish_at'] !== null
                    ? YouTubeStatus::Scheduled
                    : YouTubeStatus::Uploaded,
                'youtube_video_id' => $result['id'],
                'youtube_url' => $result['url'],
                'youtube_uploaded_at' => now(),
                'youtube_publish_at' => $result['publish_at'],
                'youtube_error' => null,
            ])->save();

            // Playlist membership never fails the upload — the video exists
            // and re-uploading would duplicate it — but the outcome is now
            // recorded on the project so a failure is visible and retryable.
            $playlists->assign($project->refresh());

            // The MP4 was being retained in case YouTube still wanted it. It
            // does not any more, so if Drive already has a copy it can go.
            try {
                $retention->prune($project->refresh());
            } catch (Throwable $e) {
                Log::warning('Local media prune failed after YouTube upload', [
                    'project_id' => $project->id,
                    'exception' => $e,
                ]);
            }
        } catch (GoogleNotConnectedException $e) {
            $this->fail($project, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('YouTube upload failed', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            $this->fail($project, $this->explain($e));

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $project = ContentProject::find($this->projectId);

        if ($project !== null && $project->youtube_status === YouTubeStatus::Uploading) {
            $this->fail($project, 'The YouTube upload did not complete. You can retry it.');
        }
    }

    private function fail(ContentProject $project, string $message): void
    {
        $project->forceFill([
            'youtube_status' => YouTubeStatus::Failed,
            'youtube_error' => $message,
        ])->save();
    }

    /** Surface the causes a user can actually act on. */
    private function explain(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'quotaExceeded') => 'The YouTube API quota for today is exhausted. Try again tomorrow.',
            str_contains($message, 'not the expected channel') => $message,
            str_contains($message, 'publish time is in the past') => $message,
            default => 'The YouTube upload failed. You can retry it.',
        };
    }
}
