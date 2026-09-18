<?php

namespace App\Services\Media;

use App\Models\ContentProject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Places uploaded media on the private disk.
 *
 * Server-controlled filenames only: the original name is kept as a display
 * label in the database and never used to build a path, so a crafted filename
 * cannot traverse out of the project directory.
 */
class MediaStorage
{
    /**
     * Store a lecture recording, replacing any previous one.
     *
     * @return array{path:string, extension:string}
     */
    public function storeAudio(ContentProject $project, UploadedFile $file): array
    {
        return $this->store($project, $file, 'audio', (array) config('media.audio_extensions'));
    }

    /**
     * Store background artwork, replacing any previous one.
     *
     * @return array{path:string, extension:string}
     */
    public function storeBackground(ContentProject $project, UploadedFile $file): array
    {
        return $this->store($project, $file, 'background', (array) config('media.image_extensions'));
    }

    /**
     * Copy another project's recording into this one.
     *
     * A copy rather than a shared reference, and that is the whole decision.
     * Every other part of this system assumes a project owns the files under
     * its own directory: purge() deletes that directory outright, the retention
     * pruner frees a project's sources once its render is safely backed up,
     * and the storage inventory attributes bytes by directory. A second project
     * pointing at the first one's file would turn each of those into a way to
     * delete a recording somebody is still using.
     *
     * The cost is disk: two projects trimming the same three-hour lecture hold
     * two copies of it. That is a cost the Storage page can see and the pruner
     * can reclaim, which is the better failure mode than a dangling path.
     *
     * @return array{path:string, extension:string}
     */
    public function copyAudioFrom(ContentProject $target, ContentProject $source): array
    {
        $disk = Storage::disk('local');
        $allowed = (array) config('media.audio_extensions');

        // The stored name was built from the allow-list when it was uploaded,
        // so this is a re-check rather than a first check — but a path is the
        // one thing worth checking twice.
        $extension = strtolower(pathinfo((string) $source->source_audio_path, PATHINFO_EXTENSION));

        if (! in_array($extension, $allowed, true)) {
            $extension = $allowed[0];
        }

        $dir = $target->storageDirectory().'/source';

        // Only ever one recording per project, whatever extension the old one
        // had — the same rule as an upload.
        foreach ($allowed as $candidate) {
            $disk->delete("{$dir}/audio.{$candidate}");
        }

        $path = "{$dir}/audio.{$extension}";
        $disk->copy($source->source_audio_path, $path);

        return ['path' => $path, 'extension' => $extension];
    }

    /**
     * @param  list<string>  $allowed
     * @return array{path:string, extension:string}
     */
    private function store(ContentProject $project, UploadedFile $file, string $basename, array $allowed): array
    {
        $disk = Storage::disk('local');
        $dir = $project->storageDirectory().'/source';

        // Derive the extension from the validated client extension, but only
        // accept it if it is on the allow-list; otherwise fall back to the
        // guessed one. Either way it is picked from a fixed set, never taken
        // verbatim from the upload.
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowed, true)) {
            $guessed = strtolower((string) $file->guessExtension());
            $extension = in_array($guessed, $allowed, true) ? $guessed : $allowed[0];
        }

        // Only ever one audio and one background per project — clear the old
        // one whatever extension it had.
        foreach ($allowed as $candidate) {
            $disk->delete("{$dir}/{$basename}.{$candidate}");
        }

        $path = $file->storeAs($dir, "{$basename}.{$extension}", ['disk' => 'local']);

        return ['path' => $path, 'extension' => $extension];
    }

    /** Remove every file belonging to a project. */
    public function purge(ContentProject $project): void
    {
        Storage::disk('local')->deleteDirectory($project->storageDirectory());
    }

    /** Absolute path of a stored file on the private disk. */
    public function path(string $relativePath): string
    {
        return Storage::disk('local')->path($relativePath);
    }
}
