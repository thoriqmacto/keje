<?php

namespace App\Observers;

use App\Models\ContentProject;
use App\Services\Media\RenderInputFingerprint;

/**
 * Keeps `render_is_stale` true to the fingerprint.
 *
 * The flag exists so the Studio list can filter on "this video's frames no
 * longer match its project" — a question that cannot be asked in SQL, because
 * staleness is a comparison against a hash computed in PHP from the project's
 * current inputs. Persisting the answer is what makes the Outdated filter
 * real rather than a client-side illusion over one page of rows.
 *
 * Computed on `saving`, so it is written in the same statement as the change
 * that caused it. Nothing has to remember to call anything.
 */
class ContentProjectObserver
{
    public function __construct(
        private readonly RenderInputFingerprint $fingerprint,
    ) {}

    public function saving(ContentProject $project): void
    {
        $project->render_is_stale = $this->isStale($project);
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
