<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Models\ContentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Start a new project from an existing one's description.
 *
 * A series is the same every week apart from the recording: same topic, same
 * speaker, same playlist, same category and language, often the same phrasing
 * in the description. Retyping all of it for episode twelve is the work this
 * removes.
 *
 * What copies and what does not is the whole design, and the rule is: copy
 * what describes the *series*, and copy nothing that describes *one
 * recording*. So the grouping and the YouTube fields come across; the audio,
 * the artwork, the render, the Drive backup, the video id and its publication
 * history do not, and cannot — a duplicate is a fresh project that has never
 * been rendered or uploaded, and every one of those columns keeps its default.
 *
 * Three fields get a deliberate value rather than a copy:
 *
 *   working_title      suffixed, because two rows reading "Kajian #11" in the
 *                      Studio list is worse than no duplicate at all.
 *   topic_sequence     the topic's next free number, not the original's.
 *                      Copying it would produce two TEMA #11s, and the number
 *                      is drawn on the video.
 *   publish_at         dropped. A schedule belongs to one video, and the one
 *                      being copied is either in the past or already taken.
 */
class ProjectDuplicateController extends Controller
{
    /** YouTube's own limit, and what the studio's title fields enforce. */
    private const TITLE_LIMIT = 100;

    public function store(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('view', $project), 404);

        $copy = new ContentProject([
            'topic_id' => $project->topic_id,
            'speaker_id' => $project->speaker_id,
            'template_key' => $project->template_key,

            // The text drawn on the frame. Part of the series' identity, and
            // the part somebody is most likely to keep and edit one word of.
            'primary_title' => $project->primary_title,
            'subtitle' => $project->subtitle,
            'part_number' => $project->part_number,

            'render_settings' => $project->render_settings,
            'youtube_metadata' => $this->metadataFor($project),
        ]);

        $copy->working_title = $this->copyTitle($project->working_title);
        $copy->topic_sequence = $project->topic?->nextSequence();
        $copy->slug = $this->uniqueSlug($request->user()->id, $copy->working_title);
        $copy->user()->associate($request->user());
        $copy->save();

        return response()->json(
            ['data' => new ContentProjectResource($copy->load(['topic', 'speaker']))],
            201,
        );
    }

    /**
     * The YouTube fields worth carrying over, without the schedule.
     *
     * publish_at is the one key that must not survive: it names a moment, and
     * the moment the original was given is either behind us or belongs to the
     * video already on the channel. A duplicate silently inheriting it would
     * either be refused at upload time as a past schedule, or quietly publish
     * on top of somebody's plan for another video.
     *
     * @return array<string, mixed>|null
     */
    private function metadataFor(ContentProject $project): ?array
    {
        $metadata = (array) ($project->youtube_metadata ?? []);

        unset($metadata['publish_at']);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * "Kajian #11" → "Kajian #11 (copy)", inside YouTube's title limit.
     *
     * Trimmed from the front of the suffix rather than the end, so the suffix
     * always survives: a truncated title that no longer says "(copy)" is two
     * identical-looking rows in the Studio list, which is the thing this
     * suffix exists to prevent.
     */
    private function copyTitle(string $original): string
    {
        $suffix = ' (copy)';
        $room = self::TITLE_LIMIT - mb_strlen($suffix);

        return mb_substr($original, 0, $room).$suffix;
    }

    /**
     * The same rule as ContentProjectController::store().
     *
     * Duplicated deliberately rather than shared: slug uniqueness is scoped to
     * one user, and the two call sites would have to agree about that forever
     * for a shared helper to be safe. It is four lines.
     */
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
