<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleService;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStatus;
use App\Exceptions\Media\TextDoesNotFitException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Jobs\RenderContentProjectJob;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\RenderJob;
use App\Services\Media\RenderQueueHealth;
use App\Services\Media\VideoRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Render dispatch, progress, and delivery of the finished MP4.
 *
 * The render itself always goes to the queue — this controller only ever
 * enqueues and reports.
 */
class ProjectRenderController extends Controller
{
    public function __construct(
        private readonly VideoRenderer $renderer,
        private readonly RenderQueueHealth $queueHealth,
    ) {}

    /**
     * Queue a render. Returns immediately with 202.
     *
     * Text is validated synchronously first: an unfittable title should be a
     * 422 the user can act on, not a queued job that fails minutes later.
     */
    public function store(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        if (! $project->hasRequiredMedia()) {
            throw ValidationException::withMessages([
                'media' => ['Upload both the lecture audio and a background image before rendering.'],
            ]);
        }

        if (! $project->hasRequiredText()) {
            throw ValidationException::withMessages([
                'primary_title' => ['Enter a primary title before rendering.'],
            ]);
        }

        // The columns can be set while the files are gone — a deploy that
        // replaced storage/, or a worker on a different release. Catch it
        // here for the same reason unfittable text is caught here: a 422 the
        // person can act on beats a queued job that fails minutes later with
        // nowhere obvious to look.
        $this->assertSourcesExist($project);

        $postActions = $this->postActions($request, $project);

        $project->load(['topic', 'speaker']);

        try {
            $this->renderer->resolveLayout($project);
        } catch (TextDoesNotFitException $e) {
            throw ValidationException::withMessages([$e->element => [$e->getMessage()]]);
        }

        // Claim the render inside a transaction so two rapid clicks cannot
        // both enqueue an attempt.
        $renderJob = DB::transaction(function () use ($project, $postActions): ?RenderJob {
            $fresh = ContentProject::whereKey($project->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->render_status->isInFlight()) {
                return null;
            }

            $fresh->forceFill([
                'render_status' => RenderStatus::Queued,
                'render_error' => null,
            ])->save();

            // Each attempt is a new row; history is never overwritten.
            return $fresh->renderJobs()->create([
                'status' => RenderJobStatus::Queued,
                'progress_percent' => 0,
                // Snapshotted with the attempt, not left on the project: the
                // job may sit on the queue for a while, and what happens after
                // it finishes must be what was asked for when it was queued.
                'post_actions' => $postActions,
            ]);
        });

        if ($renderJob === null) {
            return response()->json([
                'message' => 'A render is already in progress for this project.',
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 409);
        }

        RenderContentProjectJob::dispatch($project->id, $renderJob->id);

        return response()->json([
            'message' => 'Render queued.',
            'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
        ], 202);
    }

    /**
     * What to do once the render succeeds.
     *
     * Only ever what is actually possible: asking for a YouTube upload while
     * YouTube is disconnected would queue a job that can only fail, so an
     * unavailable destination is dropped here rather than discovered by a
     * worker twenty minutes later.
     *
     * @return array{drive_backup: bool, youtube_upload: bool}
     */
    private function postActions(Request $request, ContentProject $project): array
    {
        $requested = (array) $request->input('post_actions', []);
        $connections = GoogleConnection::where('user_id', $project->user_id)->get();

        $connected = fn (GoogleService $service): bool => $connections
            ->firstWhere('service', $service) !== null;

        return [
            'drive_backup' => (bool) ($requested['drive_backup'] ?? false)
                && $connected(GoogleService::Drive),
            'youtube_upload' => (bool) ($requested['youtube_upload'] ?? false)
                && $connected(GoogleService::YouTube),
        ];
    }

    /**
     * Both source files are really on disk, not merely recorded.
     *
     * @throws ValidationException
     */
    private function assertSourcesExist(ContentProject $project): void
    {
        $disk = Storage::disk('local');

        $sources = [
            'audio' => [$project->source_audio_path, 'lecture recording'],
            'background' => [$project->background_image_path, 'background image'],
        ];

        foreach ($sources as $field => [$path, $noun]) {
            if (! is_file($disk->path($path))) {
                throw ValidationException::withMessages([
                    $field => ["The {$noun} is no longer on the server. Please upload it again."],
                ]);
            }
        }
    }

    /** Lightweight polling endpoint for the studio's progress bar. */
    public function status(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        $latest = $project->latestRenderJob();

        // A render nobody picked up is indistinguishable from one about to
        // start — both are "queued" at 0%. Say which it is.
        $stalled = $this->queueHealth->stallReason($latest);

        return response()->json([
            'data' => [
                'status' => $project->render_status->value,
                'label' => $project->render_status->label(),
                'progress' => $latest?->progress_percent ?? 0,
                'error' => $project->render_error,
                'stalled' => $stalled !== null,
                'stalled_reason' => $stalled,
                'has_output' => filled($project->output_path),
                'rendered_at' => $project->rendered_at?->toIso8601String(),
                'attempt' => [
                    'id' => $latest?->uuid,
                    'status' => $latest?->status->value,
                    'started_at' => $latest?->started_at?->toIso8601String(),
                    'finished_at' => $latest?->finished_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Short-lived signed URLs for the rendered video.
     *
     * A `<video>` element cannot attach a bearer token, and blob-loading a
     * multi-hundred-megabyte lecture would defeat range requests. Issuing a
     * capability URL from this authenticated endpoint keeps the file off any
     * public path while letting the browser stream it normally. The links
     * expire quickly and are never persisted.
     */
    public function mediaLinks(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        if (blank($project->output_path)) {
            return response()->json([
                'data' => ['video_url' => null, 'download_url' => null, 'expires_at' => null],
            ]);
        }

        $ttl = now()->addMinutes((int) config('media.stream_link_ttl_minutes'));

        return response()->json([
            'data' => [
                'video_url' => URL::temporarySignedRoute('content-projects.stream', $ttl, [
                    'project' => $project->uuid,
                    'disposition' => 'inline',
                ]),
                'download_url' => URL::temporarySignedRoute('content-projects.stream', $ttl, [
                    'project' => $project->uuid,
                    'disposition' => 'attachment',
                ]),
                'expires_at' => $ttl->toIso8601String(),
            ],
        ]);
    }

    /**
     * Serve the rendered MP4 against a valid signature.
     *
     * Reached without a Sanctum token: the signature is the authorization, and
     * only the owner could have been issued one. The `signed` middleware has
     * already rejected anything tampered with or expired.
     */
    public function stream(Request $request, ContentProject $project): BinaryFileResponse
    {
        $path = $this->outputPath($project);

        if ($request->query('disposition') === 'attachment') {
            return response()->download($path, $this->downloadName($project), [
                'Content-Type' => 'video/mp4',
            ]);
        }

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Serve the uploaded background artwork to its owner.
     *
     * Small enough for the studio to fetch as a blob with the bearer token,
     * which keeps the preview fully Sanctum-authenticated.
     */
    public function background(Request $request, ContentProject $project): BinaryFileResponse
    {
        abort_unless($request->user()->can('view', $project), 404);
        abort_if(blank($project->background_image_path), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($project->background_image_path), 404);

        return response()->file($disk->path($project->background_image_path), [
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Stream the MP4 for in-browser playback.
     *
     * Rendered video lives on the private disk and is served only through this
     * authenticated route — never from a public directory.
     */
    public function video(Request $request, ContentProject $project): StreamedResponse|BinaryFileResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        $path = $this->outputPath($project);

        // Range requests let the browser seek without downloading the whole file.
        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /** Same file, as an attachment with a meaningful filename. */
    public function download(Request $request, ContentProject $project): BinaryFileResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        return response()->download(
            $this->outputPath($project),
            $this->downloadName($project),
            ['Content-Type' => 'video/mp4'],
        );
    }

    /** Absolute path of the rendered MP4, or 404. */
    private function outputPath(ContentProject $project): string
    {
        abort_if(blank($project->output_path), 404, 'This project has not been rendered yet.');

        $disk = Storage::disk('local');

        abort_unless($disk->exists($project->output_path), 404, 'The rendered video is no longer available.');

        return $disk->path($project->output_path);
    }

    private function downloadName(ContentProject $project): string
    {
        $parts = array_filter([
            $project->topic?->name,
            $project->topic_sequence !== null ? 'TEMA-'.$project->topic_sequence : null,
            $project->working_title,
            $project->part_number !== null ? 'PART-'.$project->part_number : null,
        ]);

        return \Illuminate\Support\Str::slug(implode(' ', $parts) ?: 'keje-render').'.mp4';
    }
}
