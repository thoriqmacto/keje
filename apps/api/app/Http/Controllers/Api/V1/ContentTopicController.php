<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreContentTopicRequest;
use App\Http\Requests\Api\V1\UpdateContentTopicRequest;
use App\Http\Resources\Api\V1\ContentTopicResource;
use App\Models\ContentTopic;
use App\Services\Google\PlaylistTopicResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lecture series / topics. A topic is what stops the user retyping
 * "Riyadhush Shalihin" on every project, and is the anchor a YouTube playlist
 * will later hang off.
 */
class ContentTopicController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $topics = ContentTopic::where('user_id', $request->user()->id)
            ->withCount('contentProjects')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => ContentTopicResource::collection($topics)]);
    }

    public function store(StoreContentTopicRequest $request): JsonResponse
    {
        $data = $request->validated();

        $topic = new ContentTopic([
            ...$data,
            'slug' => $data['slug'] ?? $this->uniqueSlug($request->user()->id, $data['name']),
        ]);
        $topic->user()->associate($request->user());
        $topic->save();

        return response()->json(
            ['data' => new ContentTopicResource($topic->loadCount('contentProjects'))],
            201,
        );
    }

    public function show(Request $request, ContentTopic $topic): JsonResponse
    {
        // 404 rather than 403 — a foreign topic should be indistinguishable
        // from one that does not exist.
        abort_unless($request->user()->can('view', $topic), 404);

        $topic->load([
            'contentProjects' => fn ($q) => $q->with(['topic', 'speaker'])
                ->orderBy('topic_sequence')
                ->orderByDesc('id'),
        ]);

        return response()->json(['data' => new ContentTopicResource($topic)]);
    }

    public function update(UpdateContentTopicRequest $request, ContentTopic $topic): JsonResponse
    {
        abort_unless($request->user()->can('update', $topic), 404);

        $topic->fill($request->validated())->save();

        return response()->json(['data' => new ContentTopicResource($topic->loadCount('contentProjects'))]);
    }

    public function destroy(Request $request, ContentTopic $topic): JsonResponse
    {
        abort_unless($request->user()->can('delete', $topic), 404);

        // Projects survive: the FK is nullOnDelete, so deleting a topic
        // ungroups its videos rather than destroying finished work.
        $topic->delete();

        return response()->json(null, 204);
    }

    /** Slugs are unique per owner; disambiguate with a numeric suffix. */
    private function uniqueSlug(int $userId, string $name): string
    {
        $base = Str::slug($name) ?: 'topic';
        $slug = $base;
        $i = 2;

        while (ContentTopic::where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * The topic for a YouTube playlist, creating the local shadow if needed.
     *
     * Lets the studio offer playlists as topics without asking anyone to
     * maintain both. Identity is the playlist id, never the title: two topics
     * that happen to share a name are not the same topic, and merging them on
     * a string match would silently reassign someone's projects.
     */
    public function resolveFromPlaylist(Request $request, PlaylistTopicResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'youtube_playlist_id' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $topic = $resolver->resolve(
            $request->user(),
            $validated['youtube_playlist_id'],
            $validated['title'] ?? null,
        );

        return response()->json(['data' => new ContentTopicResource($topic)]);
    }
}
