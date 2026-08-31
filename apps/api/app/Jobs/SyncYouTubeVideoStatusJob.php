<?php

namespace App\Jobs;

use App\Models\ContentProject;
use App\Services\Google\YouTubeVideoSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh what YouTube says about one project's video.
 *
 * On the media queue, which production already consumes — dispatching to a
 * queue nothing drains is how a job disappears silently. It is small and
 * read-only, so it costs a render nothing to share the lane.
 *
 * Scheduled videos get one of these dispatched with a delay just past their
 * publish time, which is the cheapest way to notice a publication: no polling
 * loop, no scheduler to install, and the persistent worker already runs.
 */
class SyncYouTubeVideoStatusJob implements ShouldQueue
{
    use Queueable;

    /** A read that fails twice is a quota or auth problem, not a blip. */
    public int $tries = 2;

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('media');
    }

    public function handle(YouTubeVideoSyncService $sync): void
    {
        $project = ContentProject::with('user')->find($this->projectId);

        if ($project === null || blank($project->youtube_video_id)) {
            return;
        }

        try {
            $sync->sync($project);
        } catch (Throwable $e) {
            // Never fail loudly: this is a background refresh, and the last
            // known state is still displayed.
            Log::warning('YouTube status sync failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
