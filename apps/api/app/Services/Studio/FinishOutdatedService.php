<?php

namespace App\Services\Studio;

use App\Models\ContentProject;
use App\Models\User;
use App\Services\Media\RenderDispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-renders every outdated project in a filtered view.
 *
 * "Outdated" means the current render was made from inputs the project no
 * longer has — a corrected subtitle, a renamed speaker. Fixing that one
 * project at a time is fine; fixing forty is the reason this exists.
 *
 * ── What it deliberately does not do ─────────────────────────────────────
 *
 * It queues renders. Nothing else. In particular it never touches YouTube:
 * not a delete, not an upload, not a privacy change, not a replacement. Some
 * of these projects have published videos, and replacing one costs its URL,
 * its view count and every comment — which is why that workflow is a
 * confirmed, one-at-a-time action with a typed confirmation. Reaching it from
 * a bulk button would undo every safeguard that was built for it.
 *
 * The same goes for Drive: an existing backup is left alone, and goes on
 * describing the render it actually holds.
 *
 * ── Scope ────────────────────────────────────────────────────────────────
 *
 * The filtered dataset, not the visible page. "Finish all" on a view showing
 * ten of fourteen matches has to mean all fourteen, or the button is a trap:
 * the user would have to page through and press it repeatedly, and would have
 * no way to know when they had finished. So the filters come to the server and
 * the server re-runs them.
 */
class FinishOutdatedService
{
    /**
     * A ceiling on one batch.
     *
     * Not a paging limit — the query is the whole filtered set — but a guard
     * against one click queueing a thousand hour-long encodes, which would
     * hold the media queue for days and starve every other job on it. The plan
     * reports the overflow rather than hiding it.
     */
    public const BATCH_LIMIT = 200;

    public function __construct(
        private readonly ProjectListQuery $listQuery,
        private readonly RenderDispatcher $dispatcher,
    ) {}

    /**
     * What a run would do, without doing it.
     *
     * @param  array<string, mixed>  $filters  the Studio view, already validated
     * @return array<string, mixed>
     */
    public function plan(User $user, array $filters): array
    {
        $projects = $this->outdated($user, $filters);
        // The true size of the view, which the capped collection cannot report.
        $total = $this->listQuery
            ->queryFor($user, [...$filters, 'render_status' => 'outdated'])
            ->count();

        $eligible = [];
        $inProgress = [];
        $blocked = [];

        foreach ($projects as $project) {
            if ($project->render_status->isInFlight()) {
                // Already queued or rendering. Not a failure — the thing the
                // user wants is already happening.
                $inProgress[] = $this->summarise($project);

                continue;
            }

            $blocker = $this->dispatcher->blocker($project);

            if ($blocker !== null) {
                $blocked[] = $this->summarise($project) + [
                    'reason_code' => $blocker['code'],
                    'reason' => $blocker['message'],
                ];

                continue;
            }

            $eligible[] = $this->summarise($project);
        }

        return [
            'outdated' => $total,
            'eligible' => count($eligible),
            'already_in_progress' => count($inProgress),
            'blocked' => count($blocked),
            'blocked_reasons' => $this->tally($blocked),
            // Capped for the response; the counts above are the whole set.
            'projects' => array_slice($eligible, 0, 50),
            'blocked_projects' => array_slice($blocked, 0, 50),
            'limited' => $total > self::BATCH_LIMIT,
            'batch_limit' => self::BATCH_LIMIT,
        ];
    }

    /**
     * Queue the renders.
     *
     * Eligibility is re-checked here rather than trusted from the plan. The
     * plan was a moment ago and the world moves: a render may have started, a
     * project may have been corrected and re-rendered by hand, media may have
     * been pruned. Acting on a stale count is how a bulk action queues work
     * that is no longer wanted.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(User $user, array $filters): array
    {
        $queued = [];
        $skipped = [];
        $blocked = [];

        foreach ($this->outdated($user, $filters) as $project) {
            // Re-read inside the loop: a long batch takes real time, and the
            // first project's render can finish before the last one starts.
            $fresh = ContentProject::with(['topic', 'speaker'])->find($project->id);

            if ($fresh === null || $fresh->user_id !== $user->id) {
                continue;
            }

            if (! $fresh->render_is_stale) {
                // Corrected in the meantime. Nothing to do, and re-rendering
                // would spend an hour of queue time to produce the same file.
                $skipped[] = ['id' => $fresh->uuid, 'reason' => 'no_longer_outdated'];

                continue;
            }

            if ($fresh->render_status->isInFlight()) {
                $skipped[] = ['id' => $fresh->uuid, 'reason' => 'already_in_progress'];

                continue;
            }

            $blocker = $this->dispatcher->blocker($fresh);

            if ($blocker !== null) {
                $blocked[] = [
                    'id' => $fresh->uuid,
                    'working_title' => $fresh->working_title,
                    'reason_code' => $blocker['code'],
                    'reason' => $blocker['message'],
                ];

                continue;
            }

            try {
                /*
                 * No post-actions, deliberately.
                 *
                 * A single-project render can carry "upload to YouTube when
                 * done" because somebody chose it for that render. Inferring
                 * the same from "this project was published once" would make a
                 * bulk button publish forty videos, and for anything already on
                 * YouTube it would mean a replacement — the destructive path
                 * this sprint is explicitly told not to automate.
                 */
                $job = $this->dispatcher->dispatch($fresh, postActions: null);

                if ($job === null) {
                    // Something claimed it between the check and the claim.
                    $skipped[] = ['id' => $fresh->uuid, 'reason' => 'already_in_progress'];

                    continue;
                }

                $queued[] = ['id' => $fresh->uuid, 'working_title' => $fresh->working_title];
            } catch (Throwable $e) {
                // One project's failure must not abandon the rest of the
                // batch — that would make the outcome depend on alphabetical
                // order.
                Log::warning('Bulk render dispatch failed for a project', [
                    'project_id' => $fresh->id,
                    'exception' => $e,
                ]);

                $blocked[] = [
                    'id' => $fresh->uuid,
                    'working_title' => $fresh->working_title,
                    'reason_code' => 'dispatch_failed',
                    'reason' => 'Could not queue this render. Try it from the project page.',
                ];
            }
        }

        return [
            'queued' => count($queued),
            'skipped' => count($skipped),
            'blocked' => count($blocked),
            'queued_projects' => $queued,
            'blocked_projects' => $blocked,
        ];
    }

    /**
     * The outdated projects in this view, scoped to the user.
     *
     * Built from the same query object the Studio list uses, so "the filtered
     * dataset" means exactly what the table was showing. The render filter is
     * forced: a view filtered to Published is still only asking about the
     * outdated ones within it.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, ContentProject>
     */
    private function outdated(User $user, array $filters): \Illuminate\Support\Collection
    {
        return $this->listQuery
            ->queryFor($user, [...$filters, 'render_status' => 'outdated'])
            ->limit(self::BATCH_LIMIT)
            ->get();
    }

    /** @return array<string, mixed> */
    private function summarise(ContentProject $project): array
    {
        return [
            'id' => $project->uuid,
            'working_title' => $project->working_title,
            'render_status' => $project->render_status->value,
            // Named so the confirmation can say what will *not* happen to it.
            'has_youtube_video' => filled($project->youtube_video_id),
        ];
    }

    /**
     * Blocked projects counted by reason.
     *
     * "3 blocked" is not actionable; "2 missing source media, 1 title does not
     * fit" tells somebody what to go and fix.
     *
     * @param  list<array<string, mixed>>  $blocked
     * @return array<string, int>
     */
    private function tally(array $blocked): array
    {
        $counts = [];

        foreach ($blocked as $entry) {
            $code = (string) $entry['reason_code'];
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return $counts;
    }
}
