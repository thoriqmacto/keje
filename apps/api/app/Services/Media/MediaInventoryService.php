<?php

namespace App\Services\Media;

use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * What Keje is actually keeping on disk, measured rather than assumed.
 *
 * Local disk is the one resource this app can exhaust on its own: a lecture
 * recording is hundreds of megabytes and a render is bigger. The database
 * knows what it *believes* it stored, but the two drift — a failed prune, a
 * restored backup, a job that died between writing a file and recording it —
 * and the disk is the side that fills up. So this reads the filesystem.
 *
 * ── The boundary ─────────────────────────────────────────────────────────
 *
 * This is not a file browser and must never become one. It reads only under
 * the private disk's `content/` prefix, through Laravel's Storage facade,
 * which is confined to the configured root. No caller supplies a path;
 * everything is derived from a project's own `storageDirectory()` or from
 * listing that one prefix. There is deliberately no method here that takes a
 * path from anywhere, because the moment one exists somebody will pass a
 * request parameter to it.
 *
 * Absolute server paths never leave this class.
 */
class MediaInventoryService
{
    /** Everything Keje writes lives under this prefix on the private disk. */
    private const ROOT = 'content';

    /**
     * Which subdirectory means what.
     *
     * Files outside these fall into `other`, which is a signal rather than a
     * category: it means something is writing where nothing is expected.
     */
    private const CATEGORIES = [
        'source' => 'sources',
        'renders' => 'renders',
        'thumbs' => 'thumbnails',
        'text' => 'text',
        'temp' => 'temp',
    ];

    public function __construct(
        private readonly MediaRetention $retention,
    ) {}

    /**
     * A full inventory for one user.
     *
     * @return array{
     *     totals: array<string, int>,
     *     counts: array<string, int>,
     *     projects: list<array<string, mixed>>,
     *     orphans: list<array<string, mixed>>,
     * }
     */
    public function forUser(User $user): array
    {
        $projects = ContentProject::query()
            ->where('user_id', $user->id)
            ->with(['topic'])
            ->orderByDesc('updated_at')
            ->get();

        $rows = [];
        $totals = ['sources' => 0, 'renders' => 0, 'thumbnails' => 0, 'text' => 0, 'temp' => 0, 'other' => 0];
        $counts = ['projects' => 0, 'eligible' => 0, 'correction_window' => 0, 'outdated' => 0];

        foreach ($projects as $project) {
            $usage = $this->measure($project->storageDirectory());

            // A project holding nothing is not interesting on a page about
            // disk usage — it has already been pruned, or never rendered.
            if ($usage['total'] === 0) {
                continue;
            }

            $eligibility = $this->retention->explain($project);

            foreach ($totals as $key => $_) {
                $totals[$key] += $usage['categories'][$key] ?? 0;
            }

            $counts['projects']++;
            $counts['eligible'] += $eligibility['eligible'] ? 1 : 0;
            $counts['correction_window'] += $this->retention->withinCorrectionWindow($project) ? 1 : 0;
            $counts['outdated'] += $project->render_is_stale ? 1 : 0;

            $rows[] = [
                'id' => $project->uuid,
                'working_title' => $project->working_title,
                'topic' => $project->topic?->name,

                'bytes' => $usage['categories'] + ['total' => $usage['total']],
                'files' => $usage['files'],
                'last_modified' => $usage['last_modified'],

                'render_status' => $project->render_status->value,
                'render_is_stale' => (bool) $project->render_is_stale,
                'drive_status' => $project->drive_status->value,
                'youtube_status' => $project->youtube_status->value,

                'media_pruned_at' => $project->media_pruned_at?->toIso8601String(),
                'finalized_at' => $project->finalized_at?->toIso8601String(),
                'correction_days_remaining' => $this->retention->correctionDaysRemaining($project),

                // Straight from MediaRetention, so the page explains exactly
                // what the pruner would decide.
                'prunable' => $eligibility['eligible'],
                'blocked_reasons' => $eligibility['reasons'],
            ];
        }

        $orphans = $this->orphans();

        foreach ($orphans as $orphan) {
            $totals['other'] += $orphan['bytes'];
        }

        return [
            'totals' => $totals + ['total' => array_sum($totals)],
            'counts' => $counts + ['orphans' => count($orphans)],
            'projects' => $rows,
            'orphans' => $orphans,
        ];
    }

    /**
     * What a bulk prune would actually free, without freeing it.
     *
     * Deliberately computed from the same eligibility check the prune uses, so
     * the preview cannot promise bytes the action then declines to release.
     *
     * @return array{eligible: list<array<string, mixed>>, skipped: list<array<string, mixed>>, bytes: array<string, int>}
     */
    public function prunePreview(User $user): array
    {
        $inventory = $this->forUser($user);

        $eligible = [];
        $skipped = [];
        $bytes = ['sources' => 0, 'renders' => 0, 'text' => 0, 'temp' => 0, 'total' => 0];

        foreach ($inventory['projects'] as $row) {
            if (! $row['prunable']) {
                $skipped[] = [
                    'id' => $row['id'],
                    'working_title' => $row['working_title'],
                    'reasons' => $row['blocked_reasons'],
                ];

                continue;
            }

            // Thumbnails survive a prune — they are tiny and still wanted for
            // a retry — so they are not counted as reclaimable.
            $freed = [
                'sources' => $row['bytes']['sources'],
                'renders' => $row['bytes']['renders'],
                'text' => $row['bytes']['text'],
                'temp' => $row['bytes']['temp'],
            ];

            foreach ($freed as $key => $value) {
                $bytes[$key] += $value;
                $bytes['total'] += $value;
            }

            $eligible[] = [
                'id' => $row['id'],
                'working_title' => $row['working_title'],
                'bytes' => array_sum($freed),
            ];
        }

        return ['eligible' => $eligible, 'skipped' => $skipped, 'bytes' => $bytes];
    }

    /**
     * Measure one project's directory.
     *
     * @return array{categories: array<string, int>, total: int, files: int, last_modified: ?string}
     */
    private function measure(string $directory): array
    {
        $disk = Storage::disk('local');
        $categories = ['sources' => 0, 'renders' => 0, 'thumbnails' => 0, 'text' => 0, 'temp' => 0, 'other' => 0];
        $total = 0;
        $files = 0;
        $latest = null;

        foreach ($disk->allFiles($directory) as $path) {
            $size = (int) $disk->size($path);
            $category = $this->categorise($directory, $path);

            $categories[$category] += $size;
            $total += $size;
            $files++;

            $modified = $disk->lastModified($path);

            if ($latest === null || $modified > $latest) {
                $latest = $modified;
            }
        }

        return [
            'categories' => $categories,
            'total' => $total,
            'files' => $files,
            'last_modified' => $latest === null ? null : now()->setTimestamp($latest)->toIso8601String(),
        ];
    }

    /** Which bucket a file belongs to, from the subdirectory it sits in. */
    private function categorise(string $directory, string $path): string
    {
        $relative = ltrim(substr($path, strlen($directory)), '/');
        $segment = explode('/', $relative)[0] ?? '';

        return self::CATEGORIES[$segment] ?? 'other';
    }

    /**
     * Managed directories no live project claims.
     *
     * Left over from a deleted project, or from a restore that brought files
     * back without their rows. Reported and never touched automatically: an
     * unreferenced directory is exactly as likely to be a database problem as
     * a disk one, and deleting media to tidy up a listing is the wrong way
     * round.
     *
     * Matched against *every* project, not just this user's. A directory
     * belonging to somebody else's project is not an orphan — it is theirs —
     * and counting its bytes here would both mis-state this account's usage
     * and report the existence of another account's content.
     *
     * @return list<array{id: string, bytes: int, files: int, last_modified: ?string}>
     */
    private function orphans(): array
    {
        $disk = Storage::disk('local');
        $orphans = [];

        // One query rather than one per directory. A pluck of uuids is small
        // even at thousands of projects, and the alternative is a lookup
        // inside the loop.
        $claimed = ContentProject::query()->pluck('uuid')->all();

        foreach ($disk->directories(self::ROOT) as $directory) {
            $uuid = basename($directory);

            if (in_array($uuid, $claimed, true)) {
                continue;
            }

            // Only a directory whose name is a UUID is something Keje made.
            // Anything else under the prefix is not ours to report on, let
            // alone offer to delete.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
                continue;
            }

            $usage = $this->measure($directory);

            if ($usage['total'] === 0) {
                continue;
            }

            $orphans[] = [
                'id' => $uuid,
                'bytes' => $usage['total'],
                'files' => $usage['files'],
                'last_modified' => $usage['last_modified'],
            ];
        }

        return $orphans;
    }
}
