<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DriveStatus;
use App\Enums\GoogleService;
use App\Enums\YouTubeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadToYouTubeRequest;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Jobs\UploadVideoToGoogleDriveJob;
use App\Jobs\UploadVideoToYouTubeJob;
use App\Models\ContentProject;
use App\Services\Google\GoogleClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues the Drive backup and the YouTube upload.
 *
 * Both are explicit user actions: rendering never triggers them, and each
 * claims its pipeline inside a transaction so a double click cannot enqueue
 * two uploads.
 */
class ProjectPublicationController extends Controller
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    public function drive(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $this->assertConnected(GoogleService::Drive);
        $this->assertRendered($project);

        $claimed = DB::transaction(function () use ($project): bool {
            $fresh = ContentProject::whereKey($project->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->drive_status->isInFlight()) {
                return false;
            }

            $fresh->forceFill([
                'drive_status' => DriveStatus::Uploading,
                'drive_error' => null,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return response()->json([
                'message' => 'A Drive upload is already in progress.',
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 409);
        }

        UploadVideoToGoogleDriveJob::dispatch($project->id);

        return response()->json([
            'message' => 'Drive backup queued.',
            'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
        ], 202);
    }

    public function youtube(UploadToYouTubeRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $this->assertConnected(GoogleService::YouTube);
        $this->assertRendered($project);

        // Uploading twice would create a second real video on the channel.
        if ($project->youtube_status->hasVideo() && filled($project->youtube_video_id)) {
            return response()->json([
                'message' => 'This project has already been uploaded to YouTube.',
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 409);
        }

        // Metadata sent with the request wins, so the user can adjust privacy
        // or the schedule at the moment of upload.
        if ($request->validated() !== []) {
            $project->forceFill([
                'youtube_metadata' => array_merge(
                    (array) ($project->youtube_metadata ?? []),
                    $request->validated(),
                ),
            ])->save();
        }

        $claimed = DB::transaction(function () use ($project): bool {
            $fresh = ContentProject::whereKey($project->id)->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->youtube_status->isInFlight()
                || ($fresh->youtube_status->hasVideo() && filled($fresh->youtube_video_id))) {
                return false;
            }

            $fresh->forceFill([
                'youtube_status' => YouTubeStatus::Uploading,
                'youtube_error' => null,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return response()->json([
                'message' => 'A YouTube upload is already in progress.',
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 409);
        }

        UploadVideoToYouTubeJob::dispatch($project->id);

        return response()->json([
            'message' => 'YouTube upload queued.',
            'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
        ], 202);
    }

    /**
     * Require only the service this pipeline actually uses.
     *
     * A missing Drive connection must never block a YouTube upload, and a
     * missing YouTube connection must never block a Drive backup.
     */
    private function assertConnected(GoogleService $service): void
    {
        if (! $this->clients->isConfigured($service)) {
            throw ValidationException::withMessages([
                'google' => [$service->label().' is not configured on the server.'],
            ]);
        }

        if (request()->user()->googleConnectionFor($service) === null) {
            throw ValidationException::withMessages([
                'google' => ['Connect '.$service->label().' from Settings → Integrations first.'],
            ]);
        }
    }

    private function assertRendered(ContentProject $project): void
    {
        if (blank($project->output_path)) {
            throw ValidationException::withMessages([
                'render' => ['Render the video before publishing it.'],
            ]);
        }
    }
}
