<?php

namespace App\Services\Studio;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Studio list's dataset query: filtering, searching, sorting, paging.
 *
 * All four belong on the server, and that is the whole point of this class.
 * The list used to fetch every project a user owned and hand the lot to the
 * browser, which is fine at ten projects and indefensible at a thousand: the
 * response grows without bound, and "sort by title" would only ever sort the
 * rows that happened to be downloaded.
 *
 * Two rules run through everything here:
 *
 *  1. **Nothing from the request reaches SQL as an identifier.** Sort keys are
 *     looked up in an allow-list and unknown ones fall back to the default.
 *     Values are bound. There is no path from a query string to an ORDER BY
 *     fragment.
 *
 *  2. **Every query is scoped to one user.** Filters name topics and speakers
 *     by UUID, and a UUID belonging to somebody else resolves to nothing
 *     rather than to their row — so a filter cannot be used to probe whether
 *     another account's topic exists.
 */
class ProjectListQuery
{
    /** The default when nothing is asked for, and the tie-breaker for everything else. */
    public const DEFAULT_SORT = 'updated_at';

    public const DEFAULT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 25;

    /** Offered in the UI; anything else is clamped. */
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * Sortable public keys, mapped to what they mean in SQL.
     *
     * A key absent from here cannot be sorted by, full stop. Relations are
     * expressed as correlated subqueries rather than joins: a join to topics
     * would be fine, but a join to a hasMany would multiply rows and quietly
     * corrupt both the page contents and the total count.
     *
     * @return array<string, string>
     */
    public static function sortableColumns(): array
    {
        return [
            'working_title' => 'column',
            'topic' => 'relation',
            'topic_sequence' => 'column',
            'speaker' => 'relation',
            'audio_duration' => 'column',
            'render_status' => 'column',
            'drive_status' => 'column',
            'youtube_status' => 'column',
            'created_at' => 'column',
            'updated_at' => 'column',
        ];
    }

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_keys(self::sortableColumns());
    }

    /**
     * Build and run the query.
     *
     * @param  array<string, mixed>  $params  already validated
     */
    public function paginate(User $user, array $params): LengthAwarePaginator
    {
        $query = $this->queryFor($user, $params);
        $perPage = $this->perPage($params['per_page'] ?? null);

        return $query->paginate(perPage: $perPage, page: max(1, (int) ($params['page'] ?? 1)));
    }

    /**
     * The filtered, sorted query itself, without paging.
     *
     * For callers that operate on a whole view rather than a page of it — the
     * bulk re-render, which has to mean every matching project and not the
     * twenty-five that happened to be on screen. They apply their own limit;
     * going through paginate() would clamp it to an offered page size and
     * quietly turn "all forty" into "the first twenty-five".
     *
     * @param  array<string, mixed>  $params  already validated
     * @return Builder<ContentProject>
     */
    public function queryFor(User $user, array $params): Builder
    {
        $query = ContentProject::query()
            ->where('content_projects.user_id', $user->id)
            ->with(['topic', 'speaker']);

        $this->withListSubqueries($query);
        $this->applySearch($query, $params['q'] ?? null);
        $this->applyFilters($query, $user, $params);
        $this->applySort($query, $params['sort'] ?? null, $params['direction'] ?? null);

        return $query;
    }

    /**
     * Everything the row needs, as subqueries rather than per-row lookups.
     *
     * Render progress was already a subselect. The replacement state was not:
     * the summary resource asked each project for its active replacement,
     * which is one query per row and therefore twenty-five extra queries on a
     * default page. Both now arrive with the page in a single statement.
     *
     * @param  Builder<ContentProject>  $query
     */
    private function withListSubqueries(Builder $query): void
    {
        $query
            ->select('content_projects.*')
            ->addSelect(['render_progress' => \App\Models\RenderJob::query()
                ->select('progress_percent')
                ->whereColumn('content_project_id', 'content_projects.id')
                ->latest('id')
                ->limit(1),
            ])
            // The status of the in-flight correction, or null. Two facts, one
            // column: the resource splits it back out.
            ->addSelect(['active_replacement_status' => \App\Models\YouTubeReplacement::query()
                ->select('status')
                ->whereColumn('content_project_id', 'content_projects.id')
                ->whereNotNull('active_key')
                ->limit(1),
            ]);
    }

    /**
     * Free-text search over the fields someone would actually recognise.
     *
     * whereHas rather than a join, for the same reason as the relation sorts:
     * a join through topics and speakers can duplicate a project row and make
     * the total count wrong. A correlated EXISTS cannot.
     *
     * @param  Builder<ContentProject>  $query
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $escaped = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $q) use ($escaped): void {
            $q->where(fn (Builder $w) => $this->like($w, 'content_projects.working_title', $escaped))
                ->orWhere(fn (Builder $w) => $this->like($w, 'content_projects.primary_title', $escaped))
                ->orWhere(fn (Builder $w) => $this->like($w, 'content_projects.subtitle', $escaped))
                ->orWhere(fn (Builder $w) => $this->like($w, 'content_projects.youtube_video_id', $escaped))
                ->orWhereHas('topic', fn (Builder $t) => $this->like($t, 'name', $escaped))
                ->orWhereHas('speaker', fn (Builder $s) => $this->like($s, 'name', $escaped));
        });
    }

    /**
     * The escape character for LIKE. Deliberately not a backslash.
     *
     * MySQL treats a backslash as the default LIKE escape and SQLite does not,
     * so the same query means different things on the two databases Keje runs
     * on — MariaDB in production, SQLite in the test suite. Naming an explicit
     * character sidesteps that entirely, and choosing one that needs no
     * quoting in a PHP string, a SQL literal or either driver removes the
     * layers of escaping where this kind of bug usually hides.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * Neutralise LIKE metacharacters in user input.
     *
     * Without this a search for "100%" matches every row, and a search box
     * that silently returns everything is worse than one that returns nothing:
     * it looks like it worked.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $term,
        );
    }

    /**
     * A LIKE comparison with the escape clause spelled out.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function like(Builder $query, string $column, string $pattern): Builder
    {
        return $query->whereRaw(
            $query->getQuery()->getGrammar()->wrap($column)." like ? escape '".self::LIKE_ESCAPE."'",
            [$pattern],
        );
    }

    /**
     * @param  Builder<ContentProject>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyFilters(Builder $query, User $user, array $params): void
    {
        // Resolved within the user's own rows. A UUID belonging to another
        // account finds nothing and filters to an empty set, which is the
        // safe answer: it neither errors nor confirms the row exists.
        if (filled($params['topic'] ?? null)) {
            $query->where('content_projects.topic_id', ContentTopic::query()
                ->where('user_id', $user->id)
                ->where('uuid', $params['topic'])
                ->value('id') ?? 0);
        }

        if (filled($params['speaker'] ?? null)) {
            // "none" is a real filter, not a missing value: "which of these
            // have I forgotten to attribute" is the question it answers.
            if ($params['speaker'] === 'none') {
                $query->whereNull('content_projects.speaker_id');
            } else {
                $query->where('content_projects.speaker_id', Speaker::query()
                    ->where('user_id', $user->id)
                    ->where('uuid', $params['speaker'])
                    ->value('id') ?? 0);
            }
        }

        if (filled($params['render_status'] ?? null)) {
            // Not a render_status value at all — a derived one, persisted so
            // it can be asked for in SQL. See ContentProject::render_is_stale.
            if ($params['render_status'] === 'outdated') {
                $query->where('content_projects.render_is_stale', true);
            } else {
                $query->where('content_projects.render_status', $params['render_status']);
            }
        }

        if (filled($params['drive_status'] ?? null)) {
            $query->where('content_projects.drive_status', $params['drive_status']);
        }

        if (filled($params['youtube_status'] ?? null)) {
            $this->applyYouTubeFilter($query, (string) $params['youtube_status']);
        }

        // The Working title column's own filter. Deliberately narrower than
        // `q`, which also covers the topic, the speaker and the drawn titles:
        // somebody filtering a column means that column, and a match on a
        // speaker's name would read as a bug.
        if (filled($params['working_title'] ?? null)) {
            $this->like(
                $query,
                'content_projects.working_title',
                '%'.$this->escapeLike(trim((string) $params['working_title'])).'%',
            );
        }

        if (filled($params['updated_within'] ?? null)) {
            $since = match ($params['updated_within']) {
                'today' => now()->startOfDay(),
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                default => null,
            };

            if ($since !== null) {
                $query->where('content_projects.updated_at', '>=', $since);
            }
        }
    }

    /**
     * YouTube's filter spans three different columns, on purpose.
     *
     * Our pipeline status, what Google last said, and whether a correction is
     * running are separate facts — that separation is deliberate and predates
     * this sprint. The filter reunites them for the person reading the list,
     * who thinks of "published" as one thing.
     *
     * @param  Builder<ContentProject>  $query
     */
    private function applyYouTubeFilter(Builder $query, string $value): void
    {
        match ($value) {
            // Remote states, from what Google last told us.
            'private', 'unlisted', 'processing', 'rejected', 'unavailable' => $query
                ->where('content_projects.youtube_remote_status', $value),

            // A correction in flight, whatever the pipeline says.
            'replacing' => $query->whereHas(
                'youtubeReplacements',
                fn (Builder $r) => $r->whereNotNull('active_key'),
            ),
            'replacement_failed' => $query->whereHas(
                'youtubeReplacements',
                fn (Builder $r) => $r->whereNotNull('active_key')->where('status', 'failed'),
            ),

            // "Published" means published now — which includes a video we
            // uploaded as scheduled that YouTube has since made public.
            'published' => $query->where(fn (Builder $q) => $q
                ->where('content_projects.youtube_status', 'published')
                ->orWhere('content_projects.youtube_remote_status', 'published')),

            default => $query->where('content_projects.youtube_status', $value),
        };
    }

    /**
     * Order the whole dataset, never just the page.
     *
     * The default is what the list has always done — most recently updated
     * first — and it is also the fallback for an unrecognised sort key and the
     * tie-breaker under every other sort. Ordering by a non-unique column
     * alone leaves the database free to return rows in any order it likes,
     * which makes pagination drop and repeat rows between pages.
     *
     * @param  Builder<ContentProject>  $query
     */
    private function applySort(Builder $query, ?string $sort, ?string $direction): void
    {
        $direction = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';
        $columns = self::sortableColumns();

        if ($sort === null || ! array_key_exists($sort, $columns)) {
            $query->orderBy('content_projects.updated_at', 'desc');
            $query->orderBy('content_projects.id', 'desc');

            return;
        }

        match ($columns[$sort]) {
            'relation' => $this->applyRelationSort($query, $sort, $direction),
            default => $query->orderBy(
                'content_projects.'.$this->physicalColumn($sort),
                $direction,
            ),
        };

        // Deterministic paging. Without this, two projects sharing a topic
        // sequence can swap places between requests and appear twice.
        $query->orderBy('content_projects.id', 'desc');
    }

    /**
     * Sort by a related name without joining.
     *
     * A correlated subquery keeps one row per project, which a join to a
     * nullable relation does not reliably do — and the total count has to stay
     * honest for the pagination footer to mean anything.
     *
     * Projects with no topic or speaker sort last in both directions. In
     * ascending order NULL sorts first in MySQL and MariaDB, which would open
     * an A–Z listing with a block of blanks; nobody scans a list for the rows
     * that are missing the thing they sorted by.
     *
     * @param  Builder<ContentProject>  $query
     */
    private function applyRelationSort(Builder $query, string $sort, string $direction): void
    {
        $sub = $sort === 'topic'
            ? ContentTopic::query()
                ->select('name')
                ->whereColumn('content_topics.id', 'content_projects.topic_id')
                ->limit(1)
            : Speaker::query()
                ->select('name')
                ->whereColumn('speakers.id', 'content_projects.speaker_id')
                ->limit(1);

        $query
            ->orderByRaw($sort === 'topic'
                ? 'content_projects.topic_id is null'
                : 'content_projects.speaker_id is null')
            ->orderBy($sub, $direction);
    }

    /**
     * The database column behind a public sort key.
     *
     * A second allow-list rather than a string transformation: the mapping is
     * short, and a rule like "prefix with source_" would be one rename away
     * from silently pointing at the wrong column.
     */
    private function physicalColumn(string $sort): string
    {
        return match ($sort) {
            'audio_duration' => 'source_audio_duration',
            default => $sort,
        };
    }

    /** Clamp to an offered size; never trust a number from the query string. */
    private function perPage(mixed $requested): int
    {
        $value = (int) ($requested ?? self::DEFAULT_PER_PAGE);

        return in_array($value, self::PAGE_SIZES, true) ? $value : self::DEFAULT_PER_PAGE;
    }
}
