<?php

namespace App\Services\Studio;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;

/**
 * Re-evaluates staleness when a name that gets drawn on the frame changes.
 *
 * The subtle half of persisting `render_is_stale`. The topic name and the
 * speaker's render name are render inputs — they appear on the video — so
 * renaming either genuinely invalidates every render that used it. But nothing
 * re-saves those projects, so a flag maintained only by ContentProject's own
 * observer would quietly go on claiming they were current.
 *
 * That is the failure mode a persisted derived value always has, and it is why
 * this exists rather than being left as a follow-up: an Outdated filter that
 * misses the most common way a render goes out of date is worse than no filter
 * at all, because it looks like an answer.
 *
 * Bounded by construction: the affected projects are exactly the ones attached
 * to the row the user just edited, and the update runs without touching
 * `updated_at` — a speaker being renamed is not an edit to each of their
 * lectures, and reordering the Studio list on that basis would be wrong.
 *
 * A service rather than an Eloquent observer because it watches two different
 * models, and Laravel dispatches observer methods by event name — one class
 * cannot answer `saved` for both. AppServiceProvider wires it to each.
 */
class RenderStalenessCascade
{
    public function topicSaved(ContentTopic $topic): void
    {
        if ($topic->wasChanged('name')) {
            $this->recheck($topic->contentProjects());
        }
    }

    public function speakerSaved(Speaker $speaker): void
    {
        // renderName() falls back from display_name to name, so a change to
        // either can change what is drawn on the frame.
        if ($speaker->wasChanged(['name', 'display_name'])) {
            $this->recheck($speaker->contentProjects());
        }
    }

    /**
     * Recompute the flag for the affected projects.
     *
     * Only projects that have actually been rendered can be stale, so the
     * query is narrowed before anything is loaded: a topic with fifty drafts
     * and two published videos costs two evaluations, not fifty.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\HasMany<ContentProject, *>  $relation
     */
    private function recheck($relation): void
    {
        $relation->getQuery()
            ->whereNotNull('last_render_input_hash')
            ->whereNotNull('output_path')
            ->with(['topic', 'speaker'])
            ->chunkById(100, function ($projects): void {
                foreach ($projects as $project) {
                    // The observer on ContentProject does the actual
                    // comparison; this only has to make it run again.
                    $project->timestamps = false;
                    $project->save();
                }
            });
    }
}
