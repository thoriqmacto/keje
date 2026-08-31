<?php

namespace App\Services\Google;

use App\Models\ContentProject;
use App\Models\YouTubePublication;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Support\Facades\DB;

/**
 * Writes publication history and moves the "current video" pointer.
 *
 * One place, because the pointer and the history have to agree. A project's
 * youtube_* columns are the fast path every existing screen reads; the
 * publication rows are the durable record of what has been on YouTube. Letting
 * two call sites update them independently is how you end up with a project
 * pointing at a video that history says was deleted.
 *
 * The transition is atomic on purpose (§18): a replacement must never leave a
 * window where the project points at a private temporary video while the old
 * one is still the real one.
 */
class YouTubePublicationRecorder
{
    public function __construct(
        private readonly RenderInputFingerprint $fingerprint,
    ) {}

    /**
     * Record a first publication and make it current.
     *
     * @param  array{id:string, url:string, privacy_status?:string, publish_at?:?string, title?:string}  $result
     */
    public function recordFirstPublication(ContentProject $project, array $result): YouTubePublication
    {
        return DB::transaction(function () use ($project, $result): YouTubePublication {
            $publication = $this->createPublication($project, $result);

            $publication->forceFill(['became_current_at' => now()])->save();

            $project->forceFill([
                'current_youtube_publication_id' => $publication->id,
                // Which render this video was made from. Everything the
                // correction workflow decides hangs off this one value.
                'youtube_render_input_hash' => $publication->render_input_hash,
            ])->save();

            return $publication;
        });
    }

    /**
     * Record a replacement upload that is not current yet.
     *
     * Deliberately does not touch the project pointer. The corrected video is
     * private and the old one is still what the world sees; claiming otherwise
     * here would send the studio's "open on YouTube" link to a video nobody
     * else can watch.
     *
     * @param  array{id:string, url:string, privacy_status?:string, publish_at?:?string, title?:string}  $result
     */
    public function recordPendingReplacement(
        ContentProject $project,
        array $result,
        ?YouTubePublication $replacing,
    ): YouTubePublication {
        $publication = $this->createPublication($project, $result);

        if ($replacing !== null) {
            $publication->forceFill(['replacement_of_id' => $replacing->id])->save();
        }

        return $publication;
    }

    /**
     * Hand authority from the old publication to the new one, atomically.
     *
     * Called only once the old video has actually been disposed of. Before
     * that point the old video is still the truth, however far along the
     * replacement is.
     */
    public function promote(
        ContentProject $project,
        YouTubePublication $replacement,
        ?YouTubePublication $previous,
        string $disposition,
        bool $remoteDeleted,
    ): void {
        DB::transaction(function () use ($project, $replacement, $previous, $disposition, $remoteDeleted): void {
            if ($previous !== null) {
                $previous->forceFill([
                    'replaced_at' => now(),
                    'disposition' => $disposition,
                    'remote_deleted_at' => $remoteDeleted ? now() : null,
                ])->save();
            }

            $replacement->forceFill(['became_current_at' => now()])->save();

            $project->forceFill([
                'current_youtube_publication_id' => $replacement->id,
                'youtube_video_id' => $replacement->youtube_video_id,
                'youtube_url' => $replacement->youtube_url,
                'youtube_uploaded_at' => $replacement->uploaded_at,
                'youtube_render_input_hash' => $replacement->render_input_hash,
                // The old video's playlist membership belonged to the old
                // video; the new one has to earn its own during finalisation.
                'youtube_playlist_item_id' => null,
                'youtube_playlist_added_at' => null,
                'youtube_playlist_error' => null,
                // Likewise the thumbnail: it was set on a video that is gone.
                'youtube_thumbnail_status' => null,
                'youtube_thumbnail_error' => null,
                'youtube_thumbnail_synced_at' => null,
                // The old video's remote state says nothing about this one.
                'youtube_remote_status' => null,
                'youtube_remote_privacy_status' => null,
                'youtube_remote_publish_at' => null,
                'youtube_remote_synced_at' => null,
                'youtube_remote_sync_error' => null,
            ])->save();
        });
    }

    /**
     * Backfill a publication row for a video uploaded before this table existed.
     *
     * Without it every project published by an earlier version of Keje would
     * show an empty history and, worse, have no old publication to mark
     * replaced when it is corrected. The render hash may be unknown — that is
     * honest, and reads as "uploaded before Keje tracked renders" rather than
     * as a false claim that the video is current.
     */
    public function backfillCurrent(ContentProject $project): ?YouTubePublication
    {
        if (blank($project->youtube_video_id) || $project->current_youtube_publication_id !== null) {
            return $project->currentYouTubePublication();
        }

        $existing = YouTubePublication::query()
            ->where('content_project_id', $project->id)
            ->where('youtube_video_id', $project->youtube_video_id)
            ->first();

        $publication = $existing ?? YouTubePublication::create([
            'content_project_id' => $project->id,
            'youtube_video_id' => $project->youtube_video_id,
            'youtube_url' => $project->youtube_url,
            'render_input_hash' => $project->youtube_render_input_hash,
            'title' => $project->youtube_metadata['title'] ?? $project->working_title,
            'privacy_status' => $project->youtube_remote_privacy_status,
            'publish_at' => $project->youtube_publish_at,
            'uploaded_at' => $project->youtube_uploaded_at,
            'remote_status' => $project->youtube_remote_status,
            'remote_synced_at' => $project->youtube_remote_synced_at,
        ]);

        $publication->forceFill(['became_current_at' => $project->youtube_uploaded_at ?? now()])->save();
        $project->forceFill(['current_youtube_publication_id' => $publication->id])->save();

        return $publication;
    }

    /** @param array<string, mixed> $result */
    private function createPublication(ContentProject $project, array $result): YouTubePublication
    {
        $renderJob = $project->latestRenderJob();

        return YouTubePublication::create([
            'content_project_id' => $project->id,
            'render_job_id' => $renderJob?->id,
            // The project's *current* fingerprint, not the render job's: the
            // job may predate fingerprinting, and what matters is what the
            // file being uploaded actually represents.
            'render_input_hash' => $project->last_render_input_hash
                ?? $this->fingerprint->for($project),
            'youtube_video_id' => $result['id'],
            'youtube_url' => $result['url'],
            'title' => $result['title'] ?? null,
            'privacy_status' => $result['privacy_status'] ?? null,
            'publish_at' => $result['publish_at'] ?? null,
            'uploaded_at' => now(),
        ]);
    }
}
