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
 *    need it, because that job uploads the same local file. Pruning sources is
 *    unaffected — YouTube never reads them.
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

        if ($force || $this->shouldPruneSources()) {
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
     * Only the YouTube pipeline can still want it, and only until it holds a
     * video of its own.
     */
    public function outputStillNeeded(ContentProject $project): bool
    {
        if (! (bool) config('media.retention.retain_output_for_youtube')) {
            return false;
        }

        return ! $project->youtube_status->hasVideo();
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
