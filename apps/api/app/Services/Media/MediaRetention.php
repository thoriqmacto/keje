<?php

namespace App\Services\Media;

use App\Enums\DriveStatus;
use App\Models\ContentProject;
use Illuminate\Support\Facades\Storage;

/**
 * Frees local disk once a render is safely in Drive.
 *
 * The VPS is working space. Source audio exists to be rendered and the render
 * exists to be uploaded; once Drive holds the MP4, keeping either locally buys
 * nothing but the ability to re-render.
 *
 * Two invariants, both load-bearing:
 *
 *  - Nothing is deleted unless Drive confirms it holds the render. A failed or
 *    in-flight backup prunes nothing.
 *  - The rendered MP4 is not deleted while the YouTube pipeline could still
 *    need it, because that job uploads the same local file.
 *  - Nothing is deleted while the project is still correctable: during the
 *    correction window, and always while a replacement is in flight. This is
 *    the invariant that makes "publish, notice a mistake, fix it" possible at
 *    all — see outputStillNeeded().
 *
 * Pruning nulls the *_path columns so every existing guard behaves without
 * change: hasRequiredMedia() turns false and blocks a re-render, `has_output`
 * turns false and the UI stops offering local playback. Descriptive columns
 * are untouched, so the project stays fully readable afterwards.
 */
class MediaRetention
{
    /** Subdirectories that only ever matter while rendering. */
    private const SOURCE_DIRS = ['source', 'text', 'temp'];

    /**
     * Prune whatever this project no longer needs locally.
     *
     * @return array{sources:bool, output:bool, bytes:int} what was removed
     */
    public function prune(ContentProject $project, bool $force = false): array
    {
        $freed = ['sources' => false, 'output' => false, 'bytes' => 0];

        if (! $force && ! $this->isBackedUp($project)) {
            return $freed;
        }

        if ($force || ($this->shouldPruneSources() && ! $this->sourcesStillNeeded($project))) {
            $freed['bytes'] += $this->pruneSources($project);
            $freed['sources'] = true;
        }

        if ($force || $this->shouldPruneOutput($project)) {
            $bytes = $this->pruneOutput($project);
            $freed['bytes'] += $bytes;
            $freed['output'] = true;
        }

        if ($freed['sources'] || $freed['output']) {
            $project->forceFill(['media_pruned_at' => now()])->save();
        }

        return $freed;
    }

    /** Drive provably holds the render — the precondition for deleting anything. */
    public function isBackedUp(ContentProject $project): bool
    {
        return $project->drive_status === DriveStatus::Uploaded
            && filled($project->drive_file_id);
    }

    /**
     * Whether the rendered MP4 is still needed locally.
     *
     * Three reasons it might be, in order of how badly deleting it would hurt:
     *
     *  1. A replacement is in flight and this file is what it uploads. Deleting
     *     it mid-workflow would strand a correction that cannot be retried.
     *  2. The project is inside its correction window. Publishing used to
     *     delete the render the instant YouTube confirmed it, which is exactly
     *     when mistakes become visible — so the file that would fix them
     *     vanished at the worst possible moment.
     *  3. The YouTube pipeline has not uploaded yet and still wants the file.
     *     This last one applies to the render alone; see sourcesStillNeeded().
     */
    public function outputStillNeeded(ContentProject $project): bool
    {
        if ($this->sourcesStillNeeded($project)) {
            return true;
        }

        if (! (bool) config('media.retention.retain_output_for_youtube')) {
            return false;
        }

        return ! $project->youtube_status->hasVideo();
    }

    /**
     * Whether the source audio and artwork are still needed locally.
     *
     * Deliberately not the same question as the rendered MP4. YouTube never
     * reads the sources, so "YouTube has not uploaded yet" is a reason to keep
     * the render and no reason at all to keep the recording — the two were
     * separate before this sprint and conflating them would start deleting
     * renders that were about to be uploaded.
     *
     * What the sources are kept for is a *correction*: fixing a wrong subtitle
     * means re-rendering, and re-rendering needs the recording. That is the
     * only reason they now outlive a backup, and it lasts exactly as long as
     * correcting is still on offer.
     */
    public function sourcesStillNeeded(ContentProject $project): bool
    {
        return $this->hasPendingReplacement($project)
            || $this->withinCorrectionWindow($project);
    }

    /**
     * The project is still correctable, so its working files stay.
     *
     * Ends either when the user finalises the project explicitly — the common
     * path, and the honest one, since they know whether the video is right —
     * or when the window elapses.
     *
     * A project that was never published has no window: there is nothing to
     * correct yet, and the ordinary backup rules apply.
     */
    public function withinCorrectionWindow(ContentProject $project): bool
    {
        $days = (int) config('media.retention.correction_window_days');

        if ($days <= 0 || $project->finalized_at !== null) {
            return false;
        }

        if (! $project->youtube_status->hasVideo()) {
            return false;
        }

        $published = $project->youtube_uploaded_at;

        // Published at an unknown time: err towards keeping the file. Disk is
        // cheaper than an unrecoverable lecture.
        if ($published === null) {
            return true;
        }

        return $published->copy()->addDays($days)->isFuture();
    }

    /** A correction is mid-flight and this file is its upload source. */
    public function hasPendingReplacement(ContentProject $project): bool
    {
        return $project->youtubeReplacements()->whereNotNull('active_key')->exists();
    }

    /**
     * Whether this project's files may go, and if not, why not.
     *
     * The single place that answers the question. The Storage page needs to
     * explain a blocked project rather than just refuse it, and the obvious
     * way to build that screen — re-deriving the rules in the inventory
     * service, or worse in React — produces a second copy that drifts from the
     * one that actually deletes files. When those two disagree, the UI either
     * promises to free space it cannot, or hides files it could.
     *
     * So the explanation is generated from the same predicates the pruning
     * itself uses, and every reason names something the user can act on.
     *
     * @return array{eligible: bool, reasons: list<array{code: string, message: string}>}
     */
    public function explain(ContentProject $project): array
    {
        $reasons = [];

        if (! $this->isBackedUp($project)) {
            // The oldest invariant here, and the only one that is really about
            // safety rather than convenience.
            $reasons[] = [
                'code' => 'no_backup',
                'message' => $project->drive_status === DriveStatus::Failed
                    ? 'The Drive backup failed, so nothing has a second copy yet.'
                    : 'Drive has not confirmed it holds the rendered video.',
            ];
        }

        if ($this->hasPendingReplacement($project)) {
            $reasons[] = [
                'code' => 'replacement_active',
                'message' => 'A YouTube replacement is in progress and is uploading this render.',
            ];
        }

        if ($this->withinCorrectionWindow($project)) {
            $days = $this->correctionDaysRemaining($project);

            $reasons[] = [
                'code' => 'correction_window',
                'message' => $days === null
                    ? 'Still inside its correction window.'
                    : sprintf('Correction window has %d day%s remaining.', $days, $days === 1 ? '' : 's'),
            ];
        }

        // Not a blocker on its own — a stale render with a backup and a closed
        // window is prunable — but it is the case where losing the sources
        // costs the most, so it is worth naming.
        if ($project->render_is_stale && $this->sourcesStillNeeded($project)) {
            $reasons[] = [
                'code' => 'render_outdated',
                'message' => 'The current render is outdated, so the source media is being kept to re-render from.',
            ];
        }

        if ($reasons === [] && $this->outputStillNeeded($project)) {
            $reasons[] = [
                'code' => 'awaiting_youtube',
                'message' => 'The rendered video is still needed for a YouTube upload.',
            ];
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Whole days left in the correction window, or null when it does not apply.
     *
     * Rounded up, because "0 days remaining" on a window that has not closed
     * reads as expired.
     */
    public function correctionDaysRemaining(ContentProject $project): ?int
    {
        if (! $this->withinCorrectionWindow($project) || $project->youtube_uploaded_at === null) {
            return null;
        }

        $closesAt = $project->youtube_uploaded_at
            ->copy()
            ->addDays((int) config('media.retention.correction_window_days'));

        return max(0, (int) ceil(now()->diffInDays($closesAt, absolute: false)));
    }

    private function shouldPruneSources(): bool
    {
        return (bool) config('media.retention.prune_sources_after_backup');
    }

    private function shouldPruneOutput(ContentProject $project): bool
    {
        return (bool) config('media.retention.prune_output_after_backup')
            && ! $this->outputStillNeeded($project);
    }

    /** Source audio, artwork and render scratch. Never needed again. */
    private function pruneSources(ContentProject $project): int
    {
        $disk = Storage::disk('local');
        $bytes = 0;

        foreach (self::SOURCE_DIRS as $sub) {
            $dir = "{$project->storageDirectory()}/{$sub}";

            foreach ($disk->allFiles($dir) as $file) {
                $bytes += (int) $disk->size($file);
            }

            $disk->deleteDirectory($dir);
        }

        // Null the paths, not the metadata: duration, original filename and
        // codec stay so the project reads normally after its bytes are gone.
        $project->forceFill([
            'source_audio_path' => null,
            'background_image_path' => null,
        ])->save();

        return $bytes;
    }

    private function pruneOutput(ContentProject $project): int
    {
        $disk = Storage::disk('local');
        $dir = "{$project->storageDirectory()}/renders";
        $bytes = 0;

        foreach ($disk->allFiles($dir) as $file) {
            $bytes += (int) $disk->size($file);
        }

        $disk->deleteDirectory($dir);

        // output_size and output_duration survive; only the pointer goes, which
        // is what flips `has_output` and sends the UI to the Drive link.
        $project->forceFill(['output_path' => null])->save();

        return $bytes;
    }
}
