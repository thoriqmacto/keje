<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Media\RenderFailedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Models\ContentProject;
use App\Services\Google\YouTubeThumbnailService;
use App\Services\Media\VideoFrameExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Choosing a frame from the rendered video as the YouTube thumbnail.
 *
 * Three operations, deliberately separate: generate candidates, choose one,
 * push it to YouTube. Keeping the last apart from the upload job is what makes
 * "retry thumbnail" safe — it can never reach videos.insert, so a retry cannot
 * publish a second copy of a video that already exists.
 */
class ProjectThumbnailController extends Controller
{
    public function __construct(
        private readonly VideoFrameExtractor $frames,
    ) {}

    /**
     * Extract candidate frames, or one at a requested timestamp.
     *
     * Cheap: FFmpeg seeks by keyframe and stops after one frame, so this is
     * three quick seeks rather than anything resembling a transcode.
     */
    public function generate(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $video = $this->requireRenderedVideo($project);
        $duration = (float) ($project->output_duration ?: 0);

        $validated = $request->validate([
            // A number, always — never a string that reaches a command.
            'timestamp' => ['nullable', 'numeric', 'min:0', 'max:'.max(0, $duration)],
        ]);

        $timestamps = isset($validated['timestamp'])
            ? [round((float) $validated['timestamp'], 3)]
            : $this->frames->candidateTimestamps($duration);

        if ($timestamps === []) {
            throw ValidationException::withMessages([
                'timestamp' => ['This video has no usable duration to take a frame from.'],
            ]);
        }

        $candidates = [];

        foreach ($timestamps as $timestamp) {
            $relative = sprintf(
                '%s/thumbnails/%s.jpg',
                $project->storageDirectory(),
                str_replace('.', '-', (string) $timestamp),
            );

            try {
                $this->frames->extract($video, $timestamp, $relative);
            } catch (RenderFailedException $e) {
                throw ValidationException::withMessages(['timestamp' => [$e->getMessage()]]);
            }

            $candidates[] = ['timestamp' => $timestamp, 'url' => $this->frameUrl($project, $timestamp)];
        }

        return response()->json(['data' => $candidates]);
    }

    /** Remember which frame was chosen. */
    public function select(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $this->requireRenderedVideo($project);

        $validated = $request->validate([
            'timestamp' => ['required', 'numeric', 'min:0', 'max:'.max(0, (float) ($project->output_duration ?: 0))],
        ]);

        $timestamp = round((float) $validated['timestamp'], 3);
        $relative = sprintf(
            '%s/thumbnails/%s.jpg',
            $project->storageDirectory(),
            str_replace('.', '-', (string) $timestamp),
        );

        if (! Storage::disk('local')->exists($relative)) {
            throw ValidationException::withMessages([
                'timestamp' => ['Generate that frame before selecting it.'],
            ]);
        }

        $project->forceFill([
            'thumbnail_path' => $relative,
            'thumbnail_timestamp' => $timestamp,
            'thumbnail_generated_at' => now(),
            // A new choice invalidates the previous upload's outcome.
            'youtube_thumbnail_status' => null,
            'youtube_thumbnail_error' => null,
        ])->save();

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }

    /**
     * Push the chosen thumbnail to YouTube.
     *
     * thumbnails.set only. This exists so a failed thumbnail can be retried
     * without any path back to videos.insert.
     */
    public function push(Request $request, ContentProject $project, YouTubeThumbnailService $thumbnails): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        if (blank($project->youtube_video_id)) {
            throw ValidationException::withMessages([
                'thumbnail' => ['Upload the video to YouTube first.'],
            ]);
        }

        $result = $thumbnails->set($project);

        $project->forceFill([
            'youtube_thumbnail_status' => $result['ok'] ? 'set' : 'failed',
            'youtube_thumbnail_error' => $result['error'],
            'youtube_thumbnail_synced_at' => now(),
        ])->save();

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ], $result['ok'] ? 200 : 422);
    }

    /** Serve a generated frame back to the browser for preview. */
    public function show(Request $request, ContentProject $project): BinaryFileResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        $timestamp = round((float) $request->query('timestamp', '0'), 3);
        $relative = sprintf(
            '%s/thumbnails/%s.jpg',
            $project->storageDirectory(),
            str_replace('.', '-', (string) $timestamp),
        );

        abort_unless(Storage::disk('local')->exists($relative), 404);

        return response()->file(Storage::disk('local')->path($relative), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    private function requireRenderedVideo(ContentProject $project): string
    {
        if (blank($project->output_path)) {
            throw ValidationException::withMessages([
                'thumbnail' => ['Render the video before choosing a thumbnail.'],
            ]);
        }

        $path = Storage::disk('local')->path($project->output_path);

        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'thumbnail' => ['The rendered video is no longer on this server.'],
            ]);
        }

        return $path;
    }

    private function frameUrl(ContentProject $project, float $timestamp): string
    {
        return sprintf('/content-projects/%s/thumbnail?timestamp=%s', $project->uuid, $timestamp);
    }
}
