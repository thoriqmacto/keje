<?php

namespace App\Console\Commands;

use App\Models\ContentProject;
use App\Services\Media\MediaRetention;
use Illuminate\Console\Command;

/**
 * Frees local media for projects whose correction window has closed.
 *
 * Needed because pruning is otherwise opportunistic: it happens when a Drive
 * backup or a YouTube upload finishes, and a project that is retained at that
 * moment is never revisited. Without this the correction window would mean
 * "keep the files forever" for anyone who never presses Finalise.
 *
 * Not a requirement. The explicit Finalise action frees a project's files
 * immediately and is the path most take; this is the sweeper for the rest, and
 * running it is optional in the same way that tidying up is. `deploy/systemd`
 * ships a timer for hosts that want it automated.
 */
class PruneExpiredMediaCommand extends Command
{
    protected $signature = 'media:prune-expired
                            {--dry-run : List what would be freed without deleting anything}
                            {--limit=200 : Maximum projects to consider in one pass}';

    protected $description = 'Free local media for published projects past their correction window';

    public function handle(MediaRetention $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        // Only projects that still hold bytes worth freeing. A project already
        // pruned has nothing to do and should not cost a query per run.
        $projects = ContentProject::query()
            ->where(function ($query): void {
                $query->whereNotNull('output_path')
                    ->orWhereNotNull('source_audio_path')
                    ->orWhereNotNull('background_image_path');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $freedBytes = 0;
        $freedProjects = 0;
        $retained = 0;

        foreach ($projects as $project) {
            if (! $retention->isBackedUp($project)) {
                // Never prune what Drive does not hold. This is the oldest
                // invariant in the retention rules and the sweeper does not
                // get to relax it.
                continue;
            }

            if ($retention->outputStillNeeded($project)) {
                $retained++;

                continue;
            }

            if ($dryRun) {
                $this->line("would free  {$project->uuid}  {$project->working_title}");
                $freedProjects++;

                continue;
            }

            $freed = $retention->prune($project);

            if ($freed['bytes'] > 0) {
                $freedBytes += $freed['bytes'];
                $freedProjects++;
            }
        }

        $this->info(sprintf(
            '%s %d project(s)%s. %d still inside a correction window or awaiting a replacement.',
            $dryRun ? 'Would free' : 'Freed',
            $freedProjects,
            $dryRun ? '' : sprintf(', %s', $this->humanBytes($freedBytes)),
            $retained,
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / 1024 / 1024);
        }

        return sprintf('%.2f GB', $bytes / 1024 / 1024 / 1024);
    }
}
