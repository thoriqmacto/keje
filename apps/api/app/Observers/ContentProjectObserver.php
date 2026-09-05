<?php

namespace App\Observers;

use App\Models\ContentProject;
use App\Services\Media\RenderInputFingerprint;

/**
 * Derived columns the Studio list needs the database to be able to read.
 *
 * Both exist for the same reason: the list has to filter and sort over every
 * project a user owns, not over the page that happens to be downloaded, and
 * neither of these facts could be asked for in SQL as it was stored.
 *
 *   render_is_stale                 "this video's frames no longer match its
 *                                   project" — a comparison against a hash
 *                                   computed in PHP from the current inputs.
 *
 *   youtube_planned_publish_at      the publish time somebody entered in the
 *                                   form, which lived only inside the
 *                                   youtube_metadata JSON. Sorting by YouTube
 *                                   has to order two planned projects by when
 *                                   they are planned for.
 *
 * Both are computed on `saving`, so each is written in the same statement as
 * the change that caused it. Nothing has to remember to call anything, and
 * there is no window where the row disagrees with itself.
 */
class ContentProjectObserver
{
    public function __construct(
        private readonly RenderInputFingerprint $fingerprint,
    ) {}

    public function saving(ContentProject $project): void
    {
        $project->render_is_stale = $this->isStale($project);

        // Parsed by the model, so the list, the upload job and this column
        // cannot come to disagree about what was planned.
        $project->youtube_planned_publish_at = $project->plannedPublishAt();
    }

    /**
     * The same rule as RenderInputFingerprint::isStale, without reloading.
     *
     * Nothing rendered yet, or rendered before fingerprints existed, is not
     * stale — only unknown. Claiming otherwise would mark every historical
     * project outdated on the day this shipped.
     */
    private function isStale(ContentProject $project): bool
    {
        if (blank($project->last_render_input_hash) || blank($project->output_path)) {
            return false;
        }

        return $this->fingerprint->for($project) !== $project->last_render_input_hash;
    }
}
