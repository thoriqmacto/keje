<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the Studio list a queryable table rather than a full download.
 *
 * Two things, both driven by the list's actual query shape rather than by
 * indexing every column that appears in a WHERE clause somewhere.
 *
 * ── render_is_stale ──────────────────────────────────────────────────────
 *
 * "Which of my videos were made from a render that no longer matches the
 * project?" is one of the more useful questions this list can answer, and it
 * could not be asked in SQL: staleness is a comparison between a stored hash
 * and one computed in PHP from the project's current inputs. Persisting the
 * outcome makes it filterable and removes a per-row computation from the list
 * resource. ContentProjectObserver keeps it true; see the note there about
 * why a topic or speaker rename has to cascade.
 *
 * ── Indexes ──────────────────────────────────────────────────────────────
 *
 * Every list query begins `where user_id = ?` and ends `order by <something>,
 * id desc`. That makes the useful shape composite and leading with user_id;
 * an index on updated_at alone would be almost useless here, because the
 * filter on user_id comes first and would leave the sort to a filesort anyway.
 *
 * Only the two default orderings get composite indexes. The rest — sorting by
 * title, by TEMA, by a status — are secondary paths over one user's rows,
 * which is a small set, and indexing all of them would slow every write to buy
 * nothing measurable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_projects', 'render_is_stale')) {
                $table->boolean('render_is_stale')->default(false);
            }
        });

        Schema::table('content_projects', function (Blueprint $table): void {
            // The default listing: one user's projects, newest first.
            if (! $this->hasIndex('content_projects', 'content_projects_user_id_updated_at_index')) {
                $table->index(['user_id', 'updated_at']);
            }

            // The same list sorted by age, which is the other common reading.
            if (! $this->hasIndex('content_projects', 'content_projects_user_id_created_at_index')) {
                $table->index(['user_id', 'created_at']);
            }

            // The Outdated filter, which is a narrow slice of one user's rows.
            if (! $this->hasIndex('content_projects', 'content_projects_user_id_render_is_stale_index')) {
                $table->index(['user_id', 'render_is_stale']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            $table->dropIndex('content_projects_user_id_updated_at_index');
            $table->dropIndex('content_projects_user_id_created_at_index');
            $table->dropIndex('content_projects_user_id_render_is_stale_index');
            $table->dropColumn('render_is_stale');
        });
    }

    /**
     * Guarded like every add in this codebase: MariaDB commits each DDL
     * statement immediately and cannot roll a failed migration back, so this
     * has to be safe to re-run over a half-applied state.
     */
    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $existing): bool => $existing['name'] === $index);
    }
};
