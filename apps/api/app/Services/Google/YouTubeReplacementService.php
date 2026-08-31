<?php

namespace App\Services\Google;

use App\Enums\OldVideoDisposition;
use App\Enums\ReplacementStage;
use App\Enums\ReplacementStatus;
use App\Jobs\AdvanceYouTubeReplacementJob;
use App\Models\ContentProject;
use App\Models\YouTubeReplacement;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starts, resumes and unwinds a video replacement.
 *
 * The sequence exists because YouTube offers no way to swap the file behind a
 * video id, and the obvious implementation — delete the old one, upload the new
 * one — has a failure mode that destroys a lecture: if the upload fails after
 * the delete, the user is left with nothing, and nothing can bring the old
 * video back. So the order is inverted:
 *
 *      upload the correction, privately
 *              ↓  the old video is still up, still public, untouched
 *      confirm it exists on YouTube
 *              ↓
 *      dispose of the old video
 *              ↓  only now does the new one become authoritative
 *      apply playlist, thumbnail, final visibility
 *
 * Every arrow is a place a worker can die, and every one of them is safe:
 * before the disposal the old video is still the project's video, and after it
 * the new video already exists and merely needs configuring. There is no point
 * in the sequence where a crash loses the lecture.
 *
 * Resumption is driven by YouTubeReplacement::nextStage(), which reads what
 * actually happened rather than a step counter. That is what makes a retry
 * safe to press twice.
 */
class YouTubeReplacementService
{
    public function __construct(
        private readonly YouTubeReplacementPolicy $policy,
        private readonly RenderInputFingerprint $fingerprint,
    ) {}

    /**
     * Begin correcting a published video.
     *
     * @throws RuntimeException when the project may not be replaced
     */
    public function start(
        ContentProject $project,
        OldVideoDisposition $disposition = OldVideoDisposition::Delete,
    ): YouTubeReplacement {
        $verdict = $this->policy->evaluate($project);

        if (! $verdict['allowed']) {
            throw new RuntimeException((string) $verdict['message']);
        }

        $publications = app(YouTubePublicationRecorder::class);
        // A project published before history existed has no publication row to
        // mark replaced. Backfilling one keeps the chain intact rather than
        // leaving a gap where the first video used to be.
        $oldPublication = $publications->backfillCurrent($project);

        $replacement = DB::transaction(function () use ($project, $disposition, $oldPublication): ?YouTubeReplacement {
            // Lock the project row, then re-read the world inside the lock:
            // the check and the claim have to be one indivisible step or two
            // clicks land two replacements.
            $fresh = ContentProject::whereKey($project->id)->lockForUpdate()->first();

            if ($fresh === null) {
                return null;
            }

            if ($fresh->youtubeReplacements()->whereNotNull('active_key')->exists()) {
                return null;
            }

            // A plain upload in flight would race this for the same video id.
            if ($fresh->youtube_status->isInFlight()) {
                return null;
            }

            return YouTubeReplacement::create([
                'content_project_id' => $fresh->id,
                'user_id' => $fresh->user_id,
                'status' => ReplacementStatus::Pending,
                // The lock. Unique in the database, so even two application
                // servers cannot both hold it.
                'active_key' => $fresh->id,
                // Never from the request: the video being replaced is whatever
                // the project currently points at, full stop.
                'old_video_id' => (string) $fresh->youtube_video_id,
                'old_publication_id' => $oldPublication?->id,
                'render_input_hash' => $this->fingerprint->for($fresh),
                'render_job_id' => $fresh->latestRenderJob()?->id,
                'old_disposition' => $disposition,
                'started_at' => now(),
            ]);
        });

        if ($replacement === null) {
            throw new ReplacementConflictException(
                'A replacement or upload is already in progress for this project.',
            );
        }

        AdvanceYouTubeReplacementJob::dispatch($replacement->id);

        return $replacement;
    }

    /**
     * Resume a stalled replacement.
     *
     * Clears only the failure marks. The facts — which videos exist, what has
     * been disposed of — are left exactly as they are, because they are what
     * decides where the retry picks up.
     */
    public function retry(YouTubeReplacement $replacement): YouTubeReplacement
    {
        if ($replacement->status->isTerminal()) {
            throw new ReplacementConflictException('This replacement has already finished.');
        }

        if ($replacement->status->isInFlight()) {
            throw new ReplacementConflictException('This replacement is already running.');
        }

        $replacement->forceFill([
            'status' => ReplacementStatus::Pending,
            'error' => null,
            'failed_stage' => null,
            'failed_at' => null,
        ])->save();

        AdvanceYouTubeReplacementJob::dispatch($replacement->id);

        return $replacement->refresh();
    }

    /**
     * Abandon a replacement while that is still meaningful.
     *
     * Only before the old video has been disposed of. Afterwards there is
     * nothing to go back to: the old video is gone and cancelling would leave
     * the project with no video at all, which is worse than any state the
     * workflow can otherwise reach.
     */
    public function cancel(YouTubeReplacement $replacement): YouTubeReplacement
    {
        if ($replacement->status->isTerminal()) {
            throw new ReplacementConflictException('This replacement has already finished.');
        }

        if ($replacement->old_disposed_at !== null) {
            throw new ReplacementConflictException(
                'The previous video has already been removed, so this replacement cannot be undone. Finish it instead.',
            );
        }

        if ($replacement->status->isInFlight()) {
            throw new ReplacementConflictException(
                'Wait for the current step to finish before cancelling.',
            );
        }

        // A temporary private copy exists on the channel and has to be cleaned
        // up, which is a YouTube call and therefore a queued job.
        if ($replacement->hasReplacementVideo()) {
            $replacement->forceFill([
                'status' => ReplacementStatus::Cancelling,
                'error' => null,
            ])->save();

            AdvanceYouTubeReplacementJob::dispatch($replacement->id, cancel: true);

            return $replacement->refresh();
        }

        // Nothing was ever sent to YouTube; the row is all there is to undo.
        $this->release($replacement, ReplacementStatus::Cancelled, ['cancelled_at' => now()]);

        return $replacement->refresh();
    }

    /**
     * Mark a stage as failed, keeping the replacement recoverable.
     *
     * Deliberately does not release the lock: the workflow still owns a
     * private video on the channel, and letting a second replacement start on
     * top of it is how a channel ends up with two extra copies of a lecture.
     */
    public function fail(YouTubeReplacement $replacement, ReplacementStage $stage, string $message): void
    {
        $replacement->forceFill([
            'status' => ReplacementStatus::Failed,
            'failed_stage' => $stage,
            'failed_at' => now(),
            'error' => $message,
        ])->save();
    }

    /**
     * Reach a terminal state and release the per-project lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function release(YouTubeReplacement $replacement, ReplacementStatus $status, array $attributes = []): void
    {
        $replacement->forceFill(array_merge([
            'status' => $status,
            // Releasing the unique key is what lets the next replacement
            // start. Nothing else about the row is a lock.
            'active_key' => null,
            'error' => null,
            'failed_stage' => null,
            'failed_at' => null,
        ], $attributes))->save();
    }

    /**
     * Whether a normal upload may proceed.
     *
     * The plain YouTube upload path has to refuse while a replacement holds
     * the project, or the two would fight over youtube_video_id.
     */
    public function isBlockedByReplacement(ContentProject $project): bool
    {
        return $project->youtubeReplacements()->whereNotNull('active_key')->exists();
    }
}
