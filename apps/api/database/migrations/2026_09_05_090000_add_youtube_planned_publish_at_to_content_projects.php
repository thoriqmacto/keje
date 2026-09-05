<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The intended publish time, as a column the database can sort by.
 *
 * It already existed as a value — `youtube_metadata.publish_at`, written by
 * the form — and the API reports it as `planned_publish_at`. What it could not
 * do was order a list. Sorting the Studio table by YouTube has to put a
 * project planned for Tuesday above one planned for Friday, and that means
 * comparing the two in SQL.
 *
 * Extracting it from JSON in the ORDER BY was the obvious alternative and the
 * wrong one. The stored value is an ISO-8601 string, so comparing it against
 * the real DATETIME columns beside it means comparing '2026-12-01T09:00:00Z'
 * with '2026-12-01 09:00:00' — a space sorts before a T, so every real
 * timestamp would sort before every planned one regardless of date. Casting
 * the string back to a datetime is where MariaDB and SQLite stop agreeing.
 * A real column removes the whole class of problem: one type, one comparison.
 *
 * ContentProjectObserver keeps it in step from here on; this backfills what
 * is already stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            // Guarded, like every add here: MariaDB commits each DDL statement
            // as it runs and cannot roll a failed migration back, so this has
            // to be safe to re-run over a half-applied state.
            if (! Schema::hasColumn('content_projects', 'youtube_planned_publish_at')) {
                $table->timestamp('youtube_planned_publish_at')
                    ->nullable()
                    ->after('youtube_publish_at');
            }
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            if (Schema::hasColumn('content_projects', 'youtube_planned_publish_at')) {
                $table->dropColumn('youtube_planned_publish_at');
            }
        });
    }

    /**
     * Copy what the form already stored into the new column.
     *
     * Through the query builder rather than the model, deliberately: saving a
     * ContentProject fires the observer, which recomputes the render
     * fingerprint by reading files off disk. A migration should not depend on
     * the media directory existing.
     */
    private function backfill(): void
    {
        DB::table('content_projects')
            ->select('id', 'youtube_metadata')
            ->whereNotNull('youtube_metadata')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $planned = $this->plannedFrom($row->youtube_metadata);

                    if ($planned !== null) {
                        DB::table('content_projects')
                            ->where('id', $row->id)
                            ->update(['youtube_planned_publish_at' => $planned]);
                    }
                }
            });
    }

    private function plannedFrom(mixed $metadata): ?Carbon
    {
        $decoded = json_decode((string) $metadata, true);

        if (! is_array($decoded) || blank($decoded['publish_at'] ?? null)) {
            return null;
        }

        try {
            return Carbon::parse($decoded['publish_at']);
        } catch (\Exception) {
            // Validated on the way in, so this is a hand-edited row. One bad
            // value must not abort the migration for every other project.
            return null;
        }
    }
};
