<?php

namespace App\Console\Commands;

use App\Models\ContentProject;
use App\Services\Media\MediaRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims local disk from projects already backed up to Drive.
 *
 * Pruning normally happens the moment a Drive backup succeeds. This command
 * exists for everything produced before that was true, and as a way to see
 * what would go before anything does.
 */
class MediaPruneCommand extends Command
{
    protected $signature = 'media:prune
        {--dry-run : Report what would be removed without deleting anything}
        {--project= : Restrict to one project UUID}
        {--force : Prune even a project Drive has not confirmed. Destroys the only copy.}';

    protected $description = 'Delete local media for projects whose render is backed up to Drive';

    public function handle(MediaRetention $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($force && ! $dryRun && ! $this->confirmForce()) {
            return self::FAILURE;
        }

        $query = ContentProject::query();

        if (filled($uuid = $this->option('project'))) {
            $query->where('uuid', $uuid);
        }

        $projects = $query->orderBy('id')->get();

        if ($projects->isEmpty()) {
            $this->components->info('No projects matched.');

            return self::SUCCESS;
        }

        $freed = 0;
        $pruned = 0;
        $rows = [];

        foreach ($projects as $project) {
            $onDisk = $this->bytesOnDisk($project);

            if ($onDisk === 0) {
                continue;
            }

            $eligible = $force || $retention->isBackedUp($project);

            if (! $eligible) {
                $rows[] = [$project->uuid, $this->human($onDisk), 'kept — not backed up to Drive'];

                continue;
            }

            if (! $force && $retention->outputStillNeeded($project)) {
                // Sources can still go; only the MP4 is being held.
                $rows[] = [$project->uuid, $this->human($onDisk), 'partial — MP4 held for YouTube'];
            }

            if ($dryRun) {
                $rows[] = [$project->uuid, $this->human($onDisk), 'would prune'];

                continue;
            }

            $result = $retention->prune($project, $force);

            if ($result['bytes'] > 0) {
                $freed += $result['bytes'];
                $pruned++;
                $rows[] = [$project->uuid, $this->human($result['bytes']), 'pruned'];
            }
        }

        if ($rows === []) {
            $this->components->info('Nothing to prune — no project is holding local media.');

            return self::SUCCESS;
        }

        $this->table(['Project', 'Local media', 'Outcome'], $rows);

        $dryRun
            ? $this->components->info('Dry run — nothing was deleted.')
            : $this->components->info(sprintf('Pruned %d project(s), freeing %s.', $pruned, $this->human($freed)));

        return self::SUCCESS;
    }

    private function confirmForce(): bool
    {
        $this->components->warn(
            '--force deletes local media for projects Drive has NOT confirmed. '
            .'For those, this is the only copy and it cannot be recovered.'
        );

        return $this->confirm('Continue?', false);
    }

    private function bytesOnDisk(ContentProject $project): int
    {
        $disk = Storage::disk('local');
        $bytes = 0;

        foreach ($disk->allFiles($project->storageDirectory()) as $file) {
            $bytes += (int) $disk->size($file);
        }

        return $bytes;
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return sprintf($unit === 'B' ? '%d %s' : '%.1f %s', $bytes, $unit);
            }

            $bytes /= 1024;
        }

        return '0 B';
    }
}
