<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RenderStatus;
use App\Exceptions\Media\UnusableMediaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReuseProjectAudioRequest;
use App\Http\Requests\Api\V1\UploadProjectAudioRequest;
use App\Http\Requests\Api\V1\UploadProjectBackgroundRequest;
use App\Http\Resources\Api\V1\AudioSourceResource;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Models\ContentProject;
use App\Services\Media\FfprobeService;
use App\Services\Media\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Source media uploads.
 *
 * The upload is stored first and inspected with ffprobe second: a file that
 * turns out to be unusable is deleted again and reported as a validation
 * error, so a bad upload never leaves state behind.
 */
class ProjectMediaController extends Controller
{
    public function __construct(
        private readonly FfprobeService $ffprobe,
        private readonly MediaStorage $storage,
    ) {}

    /**
     * Accepts the original lecture recording — no Audacity pre-pass. ffprobe
     * decides whether the file really carries usable audio; for an MPEG that
     * also has video, the first audio stream is used.
     */
    public function storeAudio(UploadProjectAudioRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $stored = $this->storage->storeAudio($project, $request->file('audio'));

        try {
            $probe = $this->ffprobe->inspectAudio($this->storage->path($stored['path']));
        } catch (Throwable $e) {
            // A rejected upload never leaves a file behind, whatever the
            // reason it was rejected. Only a genuinely unusable file is the
            // uploader's problem; a missing toolchain is the server's, and
            // that exception escapes to report itself as one.
            Storage::disk('local')->delete($stored['path']);

            throw $e instanceof UnusableMediaException
                ? ValidationException::withMessages(['audio' => [$e->getMessage()]])
                : $e;
        }

        $file = $request->file('audio');

        $project->forceFill([
            'source_audio_path' => $stored['path'],
            'source_audio_original_name' => $file->getClientOriginalName(),
            'source_audio_mime' => $file->getClientMimeType(),
            'source_audio_size' => $file->getSize(),
            'source_audio_duration' => $probe['duration'],
            'source_audio_codec' => $probe['codec'],
            'source_audio_sample_rate' => $probe['sample_rate'],
            'source_audio_channels' => $probe['channels'],
            'source_audio_bitrate' => $probe['bitrate'],
        ]);

        $this->clearStaleCuts($project);
        $this->promoteToMediaReady($project);

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }

    /**
     * Recordings already on this server, offered for reuse.
     *
     * One lecture often becomes several videos — the same three hours trimmed
     * three different ways — and re-uploading half a gigabyte for each of them
     * is a slow way to say "the same one again".
     *
     * Only projects whose bytes are actually still here: pruning nulls the
     * path while keeping the descriptive columns, so a project can remember
     * its recording's name and duration long after the file itself was
     * reclaimed. Offering one of those would be offering a file that is gone.
     */
    public function audioSources(Request $request): JsonResponse
    {
        $sources = ContentProject::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('source_audio_path')
            ->with('topic')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => AudioSourceResource::collection($sources)]);
    }

    /**
     * Use another project's recording here, by copying it.
     *
     * Copied rather than shared — see MediaStorage::copyAudioFrom() for why a
     * shared path would turn this project's prune into that project's data
     * loss. The trims are not copied with it: two projects reusing one lecture
     * is exactly the case where each wants a different section, so this lands
     * as the whole recording with nothing removed.
     *
     * Re-probed rather than trusted. The metadata could simply be copied from
     * the row, and it would nearly always be right — but "trust ffprobe, not
     * what something else recorded earlier" is the rule everywhere else here,
     * and reading the new copy is also the only honest proof it arrived.
     */
    public function reuseAudio(ReuseProjectAudioRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $source = ContentProject::query()
            ->where('user_id', $request->user()->id)
            ->where('uuid', $request->validated('source_project_id'))
            ->firstOrFail();

        // Copying a project onto itself would delete the file in the first
        // step and then copy from the hole it left. The only way to lose a
        // recording through this endpoint, and it is closed here.
        if ($source->is($project)) {
            throw ValidationException::withMessages([
                'source_project_id' => ['That is this project\'s own recording.'],
            ]);
        }

        if (blank($source->source_audio_path)
            || ! is_file($this->storage->path($source->source_audio_path))) {
            throw ValidationException::withMessages([
                'source_project_id' => ['That recording is no longer on the server.'],
            ]);
        }

        $stored = $this->storage->copyAudioFrom($project, $source);

        try {
            $probe = $this->ffprobe->inspectAudio($this->storage->path($stored['path']));
        } catch (Throwable $e) {
            // Same contract as an upload: a copy that turns out unusable
            // leaves no file behind.
            Storage::disk('local')->delete($stored['path']);

            throw $e instanceof UnusableMediaException
                ? ValidationException::withMessages(['source_project_id' => [$e->getMessage()]])
                : $e;
        }

        $project->forceFill([
            'source_audio_path' => $stored['path'],
            // The name the file was uploaded under, so the copy is
            // recognisable as the same recording rather than as "audio.mp3".
            'source_audio_original_name' => $source->source_audio_original_name,
            'source_audio_mime' => $source->source_audio_mime,
            'source_audio_size' => Storage::disk('local')->size($stored['path']),
            'source_audio_duration' => $probe['duration'],
            'source_audio_codec' => $probe['codec'],
            'source_audio_sample_rate' => $probe['sample_rate'],
            'source_audio_channels' => $probe['channels'],
            'source_audio_bitrate' => $probe['bitrate'],
        ]);

        $this->clearStaleCuts($project);
        $this->promoteToMediaReady($project);

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }

    /**
     * Stream the source recording, for a signed link only.
     *
     * An <audio> element cannot attach a bearer token, which is exactly why
     * the rendered video is served this way too: the short-lived signature
     * issued by /media-links is the authorization. Unauthenticated by
     * necessity, never public — the signature is the capability, and the file
     * stays on the private disk.
     *
     * response()->file() honours Range, so seeking into a 500 MB lecture
     * fetches the bytes around the playhead rather than the whole recording.
     */
    public function streamAudio(Request $request, ContentProject $project): BinaryFileResponse
    {
        abort_if(blank($project->source_audio_path), 404);

        $path = $this->storage->path($project->source_audio_path);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $project->source_audio_mime ?: 'audio/mpeg',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /** The clean background artwork — no burnt-in title text. */
    public function storeBackground(UploadProjectBackgroundRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $stored = $this->storage->storeBackground($project, $request->file('background'));

        try {
            $probe = $this->ffprobe->inspectImage($this->storage->path($stored['path']));
        } catch (Throwable $e) {
            // A rejected upload never leaves a file behind, whatever the
            // reason it was rejected. Only a genuinely unusable file is the
            // uploader's problem; a missing toolchain is the server's, and
            // that exception escapes to report itself as one.
            Storage::disk('local')->delete($stored['path']);

            throw $e instanceof UnusableMediaException
                ? ValidationException::withMessages(['background' => [$e->getMessage()]])
                : $e;
        }

        $file = $request->file('background');

        $project->forceFill([
            'background_image_path' => $stored['path'],
            'background_image_original_name' => $file->getClientOriginalName(),
            'background_image_mime' => $file->getClientMimeType(),
            'background_image_size' => $file->getSize(),
            'background_image_width' => $probe['width'],
            'background_image_height' => $probe['height'],
        ]);

        $this->promoteToMediaReady($project);

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }

    /**
     * A new recording means the old cut marks describe nothing.
     *
     * Cuts are stored as absolute timestamps into the recording, and nothing
     * ties them to the file they were drawn against. Left in place across a
     * replacement they quietly apply to the new timeline instead — removing
     * ninety seconds from somewhere nobody chose, in a video that renders
     * without complaint and is simply wrong.
     *
     * Clearing them costs the work of marking them again, which is minutes
     * and is visible. Keeping them costs a bad render that looks fine until
     * somebody watches it.
     */
    private function clearStaleCuts(ContentProject $project): void
    {
        $project->audio_edits = null;
    }

    /**
     * Move a draft to media_ready once both files are present, without
     * disturbing a project that is already queued, rendering or rendered.
     */
    private function promoteToMediaReady(ContentProject $project): void
    {
        if ($project->hasRequiredMedia()
            && in_array($project->render_status, [RenderStatus::Draft, RenderStatus::Failed], true)) {
            $project->render_status = RenderStatus::MediaReady;
            $project->render_error = null;
        }

        $project->save();
    }
}
