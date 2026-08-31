<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RenderStatus;
use App\Exceptions\Media\UnusableMediaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadProjectAudioRequest;
use App\Http\Requests\Api\V1\UploadProjectBackgroundRequest;
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
