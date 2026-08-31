<?php

namespace App\Jobs;

use App\Enums\OldVideoDisposition;
use App\Enums\ReplacementStage;
use App\Enums\ReplacementStatus;
use App\Enums\YouTubeStatus;
use App\Models\YouTubeReplacement;
use App\Services\Google\GoogleErrorTranslator;
use App\Services\Google\YouTubePlaylistAssigner;
use App\Services\Google\YouTubePublicationRecorder;
use App\Services\Google\YouTubeReplacementService;
use App\Services\Google\YouTubeService;
use App\Services\Google\YouTubeThumbnailService;
use App\Services\Google\YouTubeVideoMissingException;
use App\Services\Google\YouTubeVideoSyncService;
use App\Services\Google\YouTubeVideoUpdater;
use App\Services\Media\MediaRetention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs one stage of a replacement, then queues the next.
 *
 * One job class rather than three, because the thing that decides what to do
 * is the persisted state and not the class name. Three job classes would each
 * have to re-derive "am I actually the right step for this row", and the first
 * time a retry dispatched the wrong one it would call videos.insert a second
 * time — the single unrecoverable mistake available in this workflow.
 *
 * So: read the row, ask it what still needs doing, do exactly that, dispatch
 * this same job again. A crash anywhere leaves a row that answers the same
 * question correctly on the next attempt.
 *
 * `$tries = 1` on purpose. Laravel's own retry would re-run the whole handler,
 * and while `nextStage()` would keep that safe, a failed replacement is
 * something a person should look at rather than something to hammer at a
 * remote API. Retries are explicit and come from the user.
 */
class AdvanceYouTubeReplacementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $replacementId,
        public readonly bool $cancel = false,
    ) {
        // The queue production already drains. A new queue name here would be
        // a job that silently never runs.
        $this->onQueue('media');
    }

    public function handle(
        YouTubeService $youtube,
        YouTubeVideoUpdater $updater,
        YouTubeReplacementService $replacements,
        YouTubePublicationRecorder $publications,
        YouTubePlaylistAssigner $playlists,
        GoogleErrorTranslator $errors,
    ): void {
        $replacement = YouTubeReplacement::with(['contentProject', 'user'])->find($this->replacementId);

        if ($replacement === null || $replacement->status->isTerminal()) {
            return;
        }

        if ($this->cancel) {
            $this->runCancellation($replacement, $updater, $replacements, $errors);

            return;
        }

        $stage = $replacement->nextStage();

        if ($stage === null) {
            return;
        }

        try {
            match ($stage) {
                ReplacementStage::Upload => $this->upload($replacement, $youtube, $publications),
                ReplacementStage::DisposeOld => $this->disposeOld($replacement, $updater, $publications),
                ReplacementStage::Finalize => $this->finalize($replacement, $updater, $playlists, $replacements),
            };
        } catch (Throwable $e) {
            Log::error('YouTube replacement stage failed', [
                'replacement_id' => $replacement->id,
                'project_id' => $replacement->content_project_id,
                'stage' => $stage->value,
                'exception' => $e,
            ]);

            $fresh = $replacement->refresh();
            $replacements->fail($fresh, $stage, $this->explain($fresh, $stage, $e, $errors));

            return;
        }

        // Chain rather than loop: each stage is its own queue entry, so a
        // failure is attributable and the worker is never held for the whole
        // sequence.
        if ($replacement->refresh()->nextStage() !== null) {
            self::dispatch($replacement->id);
        }
    }

    /**
     * videos.insert, private, once.
     *
     * The guard is the first line and not a comment: this stage is only ever
     * reached with new_video_id null, and it persists the id the instant
     * YouTube returns it. The window between the API call succeeding and that
     * write is the only genuinely unsafe moment in the workflow, and it is one
     * statement wide.
     */
    private function upload(
        YouTubeReplacement $replacement,
        YouTubeService $youtube,
        YouTubePublicationRecorder $publications,
    ): void {
        if ($replacement->hasReplacementVideo()) {
            return;
        }

        $project = $replacement->contentProject;
        $disk = Storage::disk('local');

        if (blank($project->output_path) || ! $disk->exists($project->output_path)) {
            throw new \RuntimeException(
                'The corrected render is no longer on this server. Render the project again, then retry.',
            );
        }

        $replacement->forceFill([
            'status' => ReplacementStatus::Uploading,
            'upload_progress' => 0,
        ])->save();

        $result = $youtube->upload(
            user: $replacement->user,
            project: $project->load(['topic', 'speaker']),
            absolutePath: $disk->path($project->output_path),
            onProgress: function (float $fraction) use ($replacement): void {
                $replacement->forceFill(['upload_progress' => $fraction])->save();
            },
            // Unconditionally private. Whatever the project eventually wants,
            // two visible copies of the same lecture must never coexist —
            // and if the disposal then fails, the corrected copy stays
            // invisible rather than competing with the one that is still live.
            privacyOverride: 'private',
        );

        $publication = $publications->recordPendingReplacement(
            $project,
            $result,
            $replacement->oldPublication,
        );

        $replacement->forceFill([
            'status' => ReplacementStatus::Uploaded,
            'new_video_id' => $result['id'],
            'new_publication_id' => $publication->id,
            'upload_progress' => 1,
            'uploaded_at' => now(),
        ])->save();
    }

    /**
     * Remove the old video, or hide it — then hand authority to the new one.
     *
     * The promotion happens here rather than after the upload, and that is the
     * single most important ordering decision in this class: until the old
     * video stops being visible it is still the project's video, and pointing
     * the studio at a private copy would send anyone who clicked "open on
     * YouTube" to a video they cannot watch.
     */
    private function disposeOld(
        YouTubeReplacement $replacement,
        YouTubeVideoUpdater $updater,
        YouTubePublicationRecorder $publications,
    ): void {
        if ($replacement->old_disposed_at !== null) {
            return;
        }

        $replacement->forceFill(['status' => ReplacementStatus::DisposingOld])->save();

        $disposition = $replacement->old_disposition;
        $deleted = false;

        try {
            if ($disposition === OldVideoDisposition::Delete) {
                $updater->delete($replacement->user, $replacement->old_video_id);
                $deleted = true;
            } else {
                $updater->setPrivacy($replacement->user, $replacement->old_video_id, 'private');
            }
        } catch (YouTubeVideoMissingException) {
            // The video is already gone — deleted from YouTube Studio, most
            // likely. The goal of this step was for it to stop existing, and
            // it has. Treating that as a failure would strand the replacement
            // forever on a step that can never succeed.
            $deleted = true;
        }

        $project = $replacement->contentProject->refresh();
        $newPublication = $replacement->newPublication;

        if ($newPublication !== null) {
            $publications->promote(
                project: $project,
                replacement: $newPublication,
                previous: $replacement->oldPublication,
                disposition: $disposition->publicationDisposition(),
                remoteDeleted: $deleted,
            );
        }

        $replacement->forceFill([
            'status' => ReplacementStatus::OldDisposed,
            'old_disposed_at' => now(),
        ])->save();
    }

    /**
     * Playlist, thumbnail, and the visibility the project actually wanted.
     *
     * Reached only once the new video is authoritative. Everything here is
     * independently retryable and none of it can reach videos.insert, so a
     * thumbnail that YouTube refuses leaves a correctly published video with
     * one thing left to fix rather than a failed replacement.
     */
    private function finalize(
        YouTubeReplacement $replacement,
        YouTubeVideoUpdater $updater,
        YouTubePlaylistAssigner $playlists,
        YouTubeReplacementService $replacements,
    ): void {
        $replacement->forceFill(['status' => ReplacementStatus::Finalizing])->save();

        $project = $replacement->contentProject->refresh()->load(['topic', 'speaker', 'user']);
        $videoId = (string) $replacement->new_video_id;

        // The intended visibility, applied last. Up to this moment the
        // corrected video has been private on purpose.
        $result = $updater->update($project, $videoId);

        $project->forceFill([
            'youtube_status' => $result['publish_at'] !== null
                ? YouTubeStatus::Scheduled
                : YouTubeStatus::Uploaded,
            'youtube_publish_at' => $result['publish_at'],
            'youtube_error' => null,
        ])->save();

        $replacement->newPublication?->forceFill([
            'privacy_status' => $result['privacy_status'],
            'publish_at' => $result['publish_at'],
            'title' => $result['title'],
        ])->save();

        // Independent failures, each recorded against its own column. A
        // playlist that no longer exists must not undo a correct publication.
        $this->applyThumbnail($project);
        $playlists->assign($project->refresh());

        if ($result['publish_at'] !== null) {
            SyncYouTubeVideoStatusJob::dispatch($project->id)
                ->delay(Carbon::parse($result['publish_at'])->addMinutes(2));
        }

        // Read back what YouTube says about the new video, and drop the stale
        // catalog so /youtube stops listing the video that no longer exists.
        app(YouTubeVideoSyncService::class)->sync($project->refresh());
        app(\App\Services\Google\GoogleCatalogCache::class)
            ->flush($replacement->user, \App\Enums\GoogleService::YouTube);

        $replacements->release($replacement, ReplacementStatus::Completed, [
            'completed_at' => now(),
        ]);

        // Only now may the local MP4 go: it was the source of this upload, and
        // pruning it earlier would have made the retry impossible.
        try {
            app(MediaRetention::class)->prune($project->refresh());
        } catch (Throwable $e) {
            Log::warning('Local media prune failed after YouTube replacement', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Undo a replacement that has not yet disposed of the old video.
     *
     * Deletes the temporary private copy. If that fails the row stays failed
     * rather than terminal: an abandoned private video on the channel is
     * something the user should be told about, not something to hide by
     * marking the workflow cancelled.
     */
    private function runCancellation(
        YouTubeReplacement $replacement,
        YouTubeVideoUpdater $updater,
        YouTubeReplacementService $replacements,
        GoogleErrorTranslator $errors,
    ): void {
        if ($replacement->old_disposed_at !== null) {
            return;
        }

        try {
            if ($replacement->hasReplacementVideo()) {
                $updater->delete($replacement->user, (string) $replacement->new_video_id);
            }
        } catch (YouTubeVideoMissingException) {
            // Already gone. The desired end state.
        } catch (Throwable $e) {
            Log::error('Could not delete the temporary replacement video', [
                'replacement_id' => $replacement->id,
                'exception' => $e,
            ]);

            $replacements->fail(
                $replacement,
                ReplacementStage::Upload,
                'Could not delete the temporary replacement video: '
                    .$errors->translate($e, 'YouTube refused the request.')
                    .' Your published video has not changed.',
            );

            return;
        }

        // The pending publication never became current, so it is history that
        // never happened. Recorded as cancelled rather than deleted: the video
        // id genuinely existed on YouTube for a while.
        $replacement->newPublication?->forceFill([
            'disposition' => 'cancelled',
            'remote_deleted_at' => now(),
        ])->save();

        $replacements->release($replacement, ReplacementStatus::Cancelled, [
            'cancelled_at' => now(),
        ]);
    }

    /** A chosen frame, pushed to the new video. Never fails the replacement. */
    private function applyThumbnail(\App\Models\ContentProject $project): void
    {
        if (blank($project->thumbnail_path)) {
            return;
        }

        $outcome = app(YouTubeThumbnailService::class)->set($project->refresh());

        $project->forceFill([
            'youtube_thumbnail_status' => $outcome['ok'] ? 'set' : 'failed',
            'youtube_thumbnail_error' => $outcome['error'],
            'youtube_thumbnail_synced_at' => now(),
        ])->save();
    }

    /**
     * What the user should read, and what they can do about it.
     *
     * The reassuring half comes from YouTubeReplacement::blockingSummary() so
     * the two never drift: the same failure is shown as a status line in one
     * place and an error in another, and a user reading "your video is safe"
     * beside "the previous video is still published" should not have to work
     * out whether those are the same sentence.
     */
    private function explain(
        YouTubeReplacement $replacement,
        ReplacementStage $stage,
        Throwable $e,
        GoogleErrorTranslator $errors,
    ): string {
        $detail = $errors->translate($e, $e->getMessage());
        $summary = $replacement->summaryForStage($stage);

        return trim("{$summary} {$detail}");
    }

    public function failed(?Throwable $e): void
    {
        $replacement = YouTubeReplacement::find($this->replacementId);

        if ($replacement === null || $replacement->status->isTerminal()) {
            return;
        }

        // The worker died rather than the API refusing. nextStage() still
        // knows where this was, so the message says so without guessing.
        if ($replacement->status->isInFlight()) {
            app(YouTubeReplacementService::class)->fail(
                $replacement,
                $replacement->nextStage() ?? ReplacementStage::Finalize,
                'The replacement stopped unexpectedly. Nothing was lost — retry to continue from where it stopped.',
            );
        }
    }
}
