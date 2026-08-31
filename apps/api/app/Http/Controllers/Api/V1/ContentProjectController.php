<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RenderStatus;
use App\Exceptions\Media\TextDoesNotFitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreContentProjectRequest;
use App\Http\Requests\Api\V1\UpdateContentProjectRequest;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Http\Resources\Api\V1\ContentProjectSummaryResource;
use App\Jobs\SyncYouTubeVideoStatusJob;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Services\Media\MediaStorage;
use App\Services\Media\VideoRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentProjectController extends Controller
{
    public function __construct(
        private readonly VideoRenderer $renderer,
        private readonly MediaStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $projects = ContentProject::withRenderProgress()
            ->where('user_id', $request->user()->id)
            ->with(['topic', 'speaker'])
            ->orderByDesc('updated_at')
            ->get();

        // Stale-while-revalidate: the list always answers from what is
        // already stored, and anything old enough gets a background refresh.
        // Fifty projects must never mean fifty synchronous YouTube calls.
        $this->queueStaleYouTubeSyncs($projects);

        return response()->json(['data' => ContentProjectSummaryResource::collection($projects)]);
    }

    public function store(StoreContentProjectRequest $request): JsonResponse
    {
        $data = $request->validated();

        $project = new ContentProject([
            ...$this->resolveRelations($request, $data),
            'working_title' => $data['working_title'],
            'slug' => $this->uniqueSlug($request->user()->id, $data['working_title']),
            'template_key' => $data['template_key'] ?? config('media.default_template'),
            'primary_title' => $data['primary_title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'part_number' => $data['part_number'] ?? null,
            'render_settings' => $data['render_settings'] ?? null,
            'youtube_metadata' => $data['youtube_metadata'] ?? null,
        ]);
        $project->user()->associate($request->user());
        $project->save();

        return response()->json(
            ['data' => new ContentProjectResource($project->load(['topic', 'speaker']))],
            201,
        );
    }

    public function show(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }

    public function update(UpdateContentProjectRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $data = $request->validated();

        $project->fill([
            ...$this->resolveRelations($request, $data),
            ...array_intersect_key($data, array_flip([
                'working_title', 'template_key', 'primary_title', 'subtitle',
                'part_number', 'render_settings', 'youtube_metadata',
            ])),
        ]);

        // Editing text after a failed render should clear the stale error.
        if ($project->isDirty(['primary_title', 'subtitle', 'part_number'])
            && $project->render_status === RenderStatus::Failed) {
            $project->render_error = null;
        }

        $project->save();

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }

    public function destroy(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('delete', $project), 404);

        $this->storage->purge($project);
        $project->delete();

        return response()->json(null, 204);
    }

    /**
     * The resolved template layout for this project.
     *
     * Drives the browser preview, and doubles as the pre-render check: text
     * that cannot be laid out comes back as a 422 with a message the studio
     * shows before the user hits Render.
     */
    public function preview(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        $project->load(['topic', 'speaker']);

        try {
            return response()->json(['data' => $this->renderer->resolveLayout($project)]);
        } catch (TextDoesNotFitException $e) {
            throw ValidationException::withMessages([
                $e->element => [$e->getMessage()],
            ]);
        }
    }

    /**
     * Map public UUIDs onto internal foreign keys.
     *
     * The FormRequest has already proved each UUID belongs to the caller, so
     * this cannot attach another user's topic or speaker.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * Queue a refresh for videos nobody has looked at recently.
     *
     * Bounded on purpose: a handful per page view, oldest first. YouTube
     * publishes scheduled videos on its own and people change privacy from
     * the app, so the stored value drifts — but not fast enough to justify
     * spending quota on every project every time the page loads.
     *
     * @param  \Illuminate\Support\Collection<int, ContentProject>  $projects
     */
    private function queueStaleYouTubeSyncs(\Illuminate\Support\Collection $projects): void
    {
        $ttl = now()->subMinutes((int) config('services.youtube.remote_sync_ttl_minutes'));

        $projects
            ->filter(fn (ContentProject $p): bool => filled($p->youtube_video_id)
                && ($p->youtube_remote_synced_at === null || $p->youtube_remote_synced_at->lt($ttl)))
            ->sortBy(fn (ContentProject $p) => $p->youtube_remote_synced_at?->timestamp ?? 0)
            ->take((int) config('services.youtube.remote_sync_batch'))
            ->each(fn (ContentProject $p) => SyncYouTubeVideoStatusJob::dispatch($p->id));
    }

    private function resolveRelations(Request $request, array $data): array
    {
        $out = [];

        if (array_key_exists('topic_id', $data)) {
            $out['topic_id'] = $data['topic_id'] === null ? null : ContentTopic::where('user_id', $request->user()->id)
                ->where('uuid', $data['topic_id'])
                ->value('id');
        }

        if (array_key_exists('speaker_id', $data)) {
            $out['speaker_id'] = $data['speaker_id'] === null ? null : Speaker::where('user_id', $request->user()->id)
                ->where('uuid', $data['speaker_id'])
                ->value('id');
        }

        if (array_key_exists('topic_sequence', $data)) {
            $out['topic_sequence'] = $data['topic_sequence'];
        }

        return $out;
    }

    private function uniqueSlug(int $userId, string $title): string
    {
        $base = Str::slug($title) ?: 'project';
        $slug = $base;
        $i = 2;

        while (ContentProject::where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
