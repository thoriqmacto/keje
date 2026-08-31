<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadToYouTubeRequest;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Models\ContentProject;
use App\Services\Google\GoogleErrorTranslator;
use App\Services\Google\YouTubePublicationRecorder;
use App\Services\Google\YouTubeVideoMissingException;
use App\Services\Google\YouTubeVideoSyncService;
use App\Services\Google\YouTubeVideoUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Corrects a published video's metadata without touching the video itself.
 *
 * The common case by a long way. A wrong description, a missing tag, the wrong
 * privacy — YouTube edits all of those on the existing id, and the video keeps
 * its URL, its views and its comments.
 *
 * Structurally unable to re-upload: this controller can reach videos.update
 * and nothing else. That is not a comment, it is the design — the one failure
 * mode worth engineering against here is a "retry" that quietly publishes a
 * second copy of a lecture, and the way to prevent it is to make the code path
 * incapable of it rather than careful about it.
 *
 * Runs synchronously. videos.update is a single small call against metadata
 * that is already in the database; queueing it would buy a spinner and cost
 * the immediate confirmation that the correction landed.
 */
class ProjectYouTubeMetadataController extends Controller
{
    public function __construct(
        private readonly YouTubeVideoUpdater $updater,
        private readonly GoogleErrorTranslator $errors,
        private readonly YouTubePublicationRecorder $publications,
    ) {}

    public function update(UploadToYouTubeRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        if (blank($project->youtube_video_id)) {
            throw ValidationException::withMessages([
                'youtube' => ['This project has not been uploaded to YouTube yet.'],
            ]);
        }

        $connection = $request->user()->googleConnectionFor(GoogleService::YouTube);

        if ($connection === null) {
            throw ValidationException::withMessages([
                'google' => ['Connect YouTube from Settings → Integrations first.'],
            ]);
        }

        // Editing an existing video is a different permission from creating
        // one. A connection made before force-ssl was requested can upload and
        // cannot correct, and saying so beats a 403 from Google.
        if (! ($connection->capabilities()['manage_videos'] ?? false)) {
            throw ValidationException::withMessages([
                'youtube' => ['Reconnect YouTube to allow Keje to edit videos it has already uploaded.'],
            ]);
        }

        // A replacement in flight owns this video's state. Editing metadata
        // underneath it would push changes onto a video that is about to be
        // deleted, and lose them.
        $replacement = $project->activeYouTubeReplacement();

        if ($replacement !== null) {
            return response()->json([
                'message' => 'A replacement is in progress for this project. Finish or cancel it first.',
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 409);
        }

        // Metadata sent with the request wins, so the correction and the save
        // are one action rather than two the user has to remember to pair.
        if ($request->validated() !== []) {
            $project->forceFill([
                'youtube_metadata' => array_merge(
                    (array) ($project->youtube_metadata ?? []),
                    $request->validated(),
                ),
            ])->save();

            $project->refresh();
        }

        try {
            $result = $this->updater->update(
                $project->load(['topic', 'speaker', 'user']),
                (string) $project->youtube_video_id,
            );
        } catch (YouTubeVideoMissingException) {
            // Gone from under us. Not a failed edit — the thing being edited
            // does not exist, and a retry cannot help.
            return response()->json([
                'message' => 'That video no longer exists on YouTube. It may have been deleted from YouTube Studio.',
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'youtube' => [$this->errors->translate($e, 'Could not update the video on YouTube.')],
            ]);
        }

        // Keep the history snapshot honest: this row is what the video is now.
        $publication = $this->publications->backfillCurrent($project);

        $publication?->forceFill([
            'title' => $result['title'],
            'privacy_status' => $result['privacy_status'],
            'publish_at' => $result['publish_at'],
        ])->save();

        // Read back what YouTube now says rather than assuming the write took
        // effect exactly as sent — scheduling in particular is normalised on
        // Google's side.
        app(YouTubeVideoSyncService::class)->sync($project->refresh());

        return response()->json([
            'message' => 'Updated on YouTube. The video keeps the same link.',
            'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
        ]);
    }
}
