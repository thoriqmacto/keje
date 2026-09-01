<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Studio list as a server-driven dataset.
 *
 * The list used to return every project a user owned. That is fine at ten and
 * indefensible at a thousand — but the subtler problem is correctness, not
 * size: a browser can only sort and filter the rows it was given, so a
 * "sort by title" over a downloaded page silently means "sort this page",
 * and the answer changes depending on how many rows happened to arrive.
 *
 * These tests therefore care most about the things that are only true if the
 * work really happens in SQL: that ordering holds across page boundaries, that
 * totals count the whole filtered set, and that no request value can reach an
 * ORDER BY clause.
 */
class ProjectListQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /** A project with an explicit updated_at, so ordering is deterministic. */
    private function project(array $attributes = [], ?int $minutesAgo = null): ContentProject
    {
        $project = ContentProject::factory()->create([
            'user_id' => $this->user->id,
            ...$attributes,
        ]);

        if ($minutesAgo !== null) {
            // Timestamps off: the point is to control updated_at exactly, and
            // a save would overwrite it with now().
            $project->timestamps = false;
            $project->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->save();
            $project->timestamps = true;
        }

        return $project->refresh();
    }

    /** @return list<string> working titles, in the order the API returned them */
    private function titles(array $query = []): array
    {
        $response = $this->getJson('/api/v1/content-projects?'.http_build_query($query))
            ->assertOk();

        return array_column($response->json('data'), 'working_title');
    }

    // ── Pagination ──────────────────────────────────────────────────────────

    #[Test]
    public function the_list_returns_twenty_five_projects_by_default(): void
    {
        ContentProject::factory()->count(30)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/v1/content-projects')->assertOk();

        $this->assertCount(25, $response->json('data'));
        $response
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 25);
    }

    #[Test]
    public function the_second_page_carries_the_remainder(): void
    {
        ContentProject::factory()->count(30)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/v1/content-projects?page=2')->assertOk();

        $this->assertCount(5, $response->json('data'));
        $response->assertJsonPath('meta.from', 26)->assertJsonPath('meta.to', 30);
    }

    #[Test]
    public function every_offered_page_size_is_honoured(): void
    {
        ContentProject::factory()->count(12)->create(['user_id' => $this->user->id]);

        foreach ([10, 25, 50, 100] as $size) {
            $this->getJson("/api/v1/content-projects?per_page={$size}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', $size);
        }
    }

    #[Test]
    public function an_absurd_page_size_is_clamped_rather_than_obeyed(): void
    {
        ContentProject::factory()->count(3)->create(['user_id' => $this->user->id]);

        // The whole point of paginating is that the response size has a
        // ceiling. A request may not lift it.
        $this->getJson('/api/v1/content-projects?per_page=999999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function pagination_never_reaches_another_users_projects(): void
    {
        ContentProject::factory()->count(5)->create(['user_id' => $this->user->id]);
        ContentProject::factory()->count(40)->create(['user_id' => User::factory()->create()->id]);

        // The total is as revealing as the rows: a count of 45 would disclose
        // how much content another account holds.
        $this->getJson('/api/v1/content-projects')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    // ── Default order ───────────────────────────────────────────────────────

    #[Test]
    public function the_default_order_is_most_recently_updated_first(): void
    {
        $this->project(['working_title' => 'Oldest'], minutesAgo: 300);
        $this->project(['working_title' => 'Newest'], minutesAgo: 1);
        $this->project(['working_title' => 'Middle'], minutesAgo: 60);

        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->titles());
    }

    #[Test]
    public function ordering_holds_across_a_page_boundary(): void
    {
        // The assertion that separates real server-side sorting from sorting
        // whatever happened to arrive: page two must *continue* page one. A
        // browser sorting a downloaded page would restart the alphabet here.
        foreach (range(1, 12) as $i) {
            $this->project(['working_title' => sprintf('Project %02d', $i)]);
        }

        $sorted = ['sort' => 'working_title', 'direction' => 'asc', 'per_page' => 10];

        $this->assertSame(
            ['Project 01', 'Project 02', 'Project 03', 'Project 04', 'Project 05',
                'Project 06', 'Project 07', 'Project 08', 'Project 09', 'Project 10'],
            $this->titles($sorted),
        );

        $this->assertSame(
            ['Project 11', 'Project 12'],
            $this->titles([...$sorted, 'page' => 2]),
        );
    }

    #[Test]
    public function paging_is_stable_when_the_sorted_column_ties(): void
    {
        // Twelve projects with the same TEMA. Ordering by that column alone
        // leaves the database free to return rows in any order, which makes
        // pages overlap and drop rows. The id tie-breaker is what prevents it.
        foreach (range(1, 12) as $i) {
            $this->project(['working_title' => "Tied {$i}", 'topic_sequence' => 7]);
        }

        $page1 = $this->titles(['per_page' => 10, 'sort' => 'topic_sequence', 'direction' => 'asc']);
        $page2 = $this->titles([
            'per_page' => 10, 'page' => 2,
            'sort' => 'topic_sequence', 'direction' => 'asc',
        ]);

        $this->assertCount(10, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame(
            [],
            array_intersect($page1, $page2),
            'A row appearing on two pages means the ordering is not deterministic.',
        );
    }

    // ── Sorting ─────────────────────────────────────────────────────────────

    #[Test]
    public function working_title_sorts_both_ways(): void
    {
        $this->project(['working_title' => 'Bravo']);
        $this->project(['working_title' => 'Alpha']);
        $this->project(['working_title' => 'Charlie']);

        $this->assertSame(
            ['Alpha', 'Bravo', 'Charlie'],
            $this->titles(['sort' => 'working_title', 'direction' => 'asc']),
        );
        $this->assertSame(
            ['Charlie', 'Bravo', 'Alpha'],
            $this->titles(['sort' => 'working_title', 'direction' => 'desc']),
        );
    }

    #[Test]
    public function tema_sorts_numerically(): void
    {
        $this->project(['working_title' => 'Ten', 'topic_sequence' => 10]);
        $this->project(['working_title' => 'Two', 'topic_sequence' => 2]);

        // A string sort would put "10" before "2".
        $this->assertSame(
            ['Two', 'Ten'],
            $this->titles(['sort' => 'topic_sequence', 'direction' => 'asc']),
        );
    }

    #[Test]
    public function a_related_topic_name_can_be_sorted_on(): void
    {
        $riyadh = ContentTopic::factory()->create(['user_id' => $this->user->id, 'name' => 'Riyadhush']);
        $kajian = ContentTopic::factory()->create(['user_id' => $this->user->id, 'name' => 'Kajian']);

        $this->project(['working_title' => 'R', 'topic_id' => $riyadh->id]);
        $this->project(['working_title' => 'K', 'topic_id' => $kajian->id]);

        $this->assertSame(['K', 'R'], $this->titles(['sort' => 'topic', 'direction' => 'asc']));
        $this->assertSame(['R', 'K'], $this->titles(['sort' => 'topic', 'direction' => 'desc']));
    }

    #[Test]
    public function projects_without_a_topic_sort_last_in_both_directions(): void
    {
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id, 'name' => 'Kajian']);
        $this->project(['working_title' => 'Has topic', 'topic_id' => $topic->id]);
        $this->project(['working_title' => 'No topic', 'topic_id' => null]);

        // NULL sorts first in MySQL ascending, which would open an A–Z listing
        // with the rows that are missing the thing you sorted by. Nobody wants
        // to scroll past those to reach the data.
        $this->assertSame(
            ['Has topic', 'No topic'],
            $this->titles(['sort' => 'topic', 'direction' => 'asc']),
        );
        $this->assertSame(
            ['Has topic', 'No topic'],
            $this->titles(['sort' => 'topic', 'direction' => 'desc']),
        );
    }

    #[Test]
    public function a_related_speaker_name_can_be_sorted_on(): void
    {
        $zaid = Speaker::factory()->create(['user_id' => $this->user->id, 'name' => 'Zaid']);
        $ahmad = Speaker::factory()->create(['user_id' => $this->user->id, 'name' => 'Ahmad']);

        $this->project(['working_title' => 'Z', 'speaker_id' => $zaid->id]);
        $this->project(['working_title' => 'A', 'speaker_id' => $ahmad->id]);

        $this->assertSame(['A', 'Z'], $this->titles(['sort' => 'speaker', 'direction' => 'asc']));
    }

    #[Test]
    public function a_relation_sort_does_not_duplicate_or_lose_rows(): void
    {
        // A join to a nullable relation is the obvious implementation and the
        // wrong one: it can multiply rows and make the total disagree with the
        // page. Correlated subqueries cannot.
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id]);
        ContentProject::factory()->count(4)->create([
            'user_id' => $this->user->id,
            'topic_id' => $topic->id,
        ]);
        ContentProject::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'topic_id' => null,
        ]);

        $this->getJson('/api/v1/content-projects?sort=topic&direction=asc')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.total', 7);
    }

    // ── Sort safety ─────────────────────────────────────────────────────────

    #[Test]
    public function an_injection_shaped_sort_key_is_refused(): void
    {
        ContentProject::factory()->count(2)->create(['user_id' => $this->user->id]);

        foreach ([
            'working_title; drop table content_projects',
            'id) or 1=1--',
            '(select password from users limit 1)',
            'users.password',
        ] as $attempt) {
            // Falls back to the default order rather than erroring: a stale
            // bookmark should still show somebody their projects. The
            // allow-list is what makes that safe.
            $this->getJson('/api/v1/content-projects?sort='.urlencode($attempt))
                ->assertOk()
                ->assertJsonCount(2, 'data');
        }

        // Still there, which it would not be if the first attempt had run.
        $this->assertSame(2, ContentProject::count());
    }

    #[Test]
    public function an_unknown_sort_column_falls_back_to_the_default(): void
    {
        $this->project(['working_title' => 'Older'], minutesAgo: 100);
        $this->project(['working_title' => 'Newer'], minutesAgo: 1);

        $this->assertSame(
            ['Newer', 'Older'],
            $this->titles(['sort' => 'nonexistent_column', 'direction' => 'asc']),
        );
    }

    // ── Search ──────────────────────────────────────────────────────────────

    #[Test]
    public function search_matches_the_working_title(): void
    {
        // The factory gives every project the same primary_title, and search
        // covers that field too — so the term has to be one that only appears
        // in the title under test, or this would pass for the wrong reason.
        $this->project(['working_title' => 'Keutamaan Lapar', 'primary_title' => 'A']);
        $this->project(['working_title' => 'Something else', 'primary_title' => 'B']);

        $this->assertSame(['Keutamaan Lapar'], $this->titles(['q' => 'lapar']));
    }

    #[Test]
    public function search_also_covers_the_drawn_titles(): void
    {
        $this->project(['working_title' => 'Untitled draft', 'primary_title' => 'Keutamaan Lapar']);
        $this->project(['working_title' => 'Another', 'primary_title' => 'Something else']);

        // Working titles are often placeholders; the text actually drawn on
        // the video is frequently what someone remembers.
        $this->assertSame(['Untitled draft'], $this->titles(['q' => 'Keutamaan']));
    }

    #[Test]
    public function search_reaches_the_topic_and_speaker_names(): void
    {
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id, 'name' => 'Riyadhush Shalihin']);
        $speaker = Speaker::factory()->create(['user_id' => $this->user->id, 'name' => 'Ahmad']);

        $this->project(['working_title' => 'By topic', 'topic_id' => $topic->id, 'primary_title' => 'A']);
        $this->project(['working_title' => 'By speaker', 'speaker_id' => $speaker->id, 'primary_title' => 'B']);
        $this->project(['working_title' => 'Neither', 'primary_title' => 'C']);

        $this->assertSame(['By topic'], $this->titles(['q' => 'Riyadhush']));
        $this->assertSame(['By speaker'], $this->titles(['q' => 'Ahmad']));
    }

    #[Test]
    public function a_search_wildcard_is_matched_literally(): void
    {
        $this->project(['working_title' => '100% Complete', 'primary_title' => 'A']);
        $this->project(['working_title' => 'Anything at all', 'primary_title' => 'B']);

        // Unescaped, "%" would match every row — a search box that returns
        // everything looks broken in a way that is hard to diagnose.
        $this->assertSame(['100% Complete'], $this->titles(['q' => '100%']));
    }

    #[Test]
    public function search_totals_count_the_whole_match_not_the_page(): void
    {
        ContentProject::factory()->count(30)->create([
            'user_id' => $this->user->id,
            'working_title' => 'Kajian lecture',
        ]);
        ContentProject::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'working_title' => 'Unrelated',
        ]);

        $this->getJson('/api/v1/content-projects?q=Kajian')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 30);
    }

    #[Test]
    public function search_never_reaches_another_users_projects(): void
    {
        ContentProject::factory()->create([
            'user_id' => User::factory()->create()->id,
            'working_title' => 'Someone elses Kajian',
        ]);

        $this->assertSame([], $this->titles(['q' => 'Kajian']));
    }

    // ── Filters ─────────────────────────────────────────────────────────────

    #[Test]
    public function projects_can_be_filtered_by_topic(): void
    {
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id]);
        $this->project(['working_title' => 'In topic', 'topic_id' => $topic->id]);
        $this->project(['working_title' => 'Out of topic']);

        $this->assertSame(['In topic'], $this->titles(['topic' => $topic->uuid]));
    }

    #[Test]
    public function a_foreign_topic_filter_matches_nothing_and_reveals_nothing(): void
    {
        $foreign = ContentTopic::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->project(['working_title' => 'Mine']);

        // Neither an error nor a leak. A 404 or a 422 here would confirm that
        // somebody else's topic exists.
        $this->getJson("/api/v1/content-projects?topic={$foreign->uuid}")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function speaker_none_finds_the_unattributed_projects(): void
    {
        $speaker = Speaker::factory()->create(['user_id' => $this->user->id]);
        $this->project(['working_title' => 'Attributed', 'speaker_id' => $speaker->id]);
        $this->project(['working_title' => 'Unattributed', 'speaker_id' => null]);

        // "Which of these have I forgotten to attribute" is a real question,
        // and it cannot be asked by naming a speaker.
        $this->assertSame(['Unattributed'], $this->titles(['speaker' => 'none']));
    }

    #[Test]
    public function each_pipeline_filters_independently(): void
    {
        $this->project([
            'working_title' => 'Rendered',
            'render_status' => RenderStatus::Rendered,
        ]);
        $this->project([
            'working_title' => 'Backed up',
            'drive_status' => DriveStatus::Uploaded,
        ]);
        $this->project([
            'working_title' => 'On YouTube',
            'youtube_status' => YouTubeStatus::Published,
        ]);

        $this->assertSame(['Rendered'], $this->titles(['render_status' => 'rendered']));
        $this->assertSame(['Backed up'], $this->titles(['drive_status' => 'uploaded']));
        $this->assertSame(['On YouTube'], $this->titles(['youtube_status' => 'published']));
    }

    #[Test]
    public function published_includes_a_scheduled_video_youtube_has_since_released(): void
    {
        // Our pipeline froze at "scheduled" when videos.insert returned;
        // YouTube has published it since. The person filtering for published
        // videos means the second fact.
        $this->project([
            'working_title' => 'Published by YouTube',
            'youtube_status' => YouTubeStatus::Scheduled,
            'youtube_remote_status' => 'published',
        ]);
        $this->project(['working_title' => 'Still scheduled', 'youtube_status' => YouTubeStatus::Scheduled]);

        $this->assertSame(['Published by YouTube'], $this->titles(['youtube_status' => 'published']));
    }

    #[Test]
    public function filters_search_and_sorting_combine(): void
    {
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id, 'name' => 'Kajian']);

        $this->project([
            'working_title' => 'Bravo lecture',
            'topic_id' => $topic->id,
            'youtube_status' => YouTubeStatus::Published,
        ]);
        $this->project([
            'working_title' => 'Alpha lecture',
            'topic_id' => $topic->id,
            'youtube_status' => YouTubeStatus::Published,
        ]);
        // Right topic, wrong status.
        $this->project([
            'working_title' => 'Charlie lecture',
            'topic_id' => $topic->id,
        ]);
        // Right status, no topic.
        $this->project([
            'working_title' => 'Delta lecture',
            'youtube_status' => YouTubeStatus::Published,
        ]);

        $this->assertSame(
            ['Alpha lecture', 'Bravo lecture'],
            $this->titles([
                'q' => 'lecture',
                'topic' => $topic->uuid,
                'youtube_status' => 'published',
                'sort' => 'working_title',
                'direction' => 'asc',
            ]),
        );
    }

    // ── Column filters ──────────────────────────────────────────────────────

    #[Test]
    public function the_working_title_filter_is_narrower_than_the_global_search(): void
    {
        $speaker = Speaker::factory()->create(['user_id' => $this->user->id, 'name' => 'Ahmad']);

        $this->project(['working_title' => 'Ahmad lecture', 'primary_title' => 'A']);
        $this->project([
            'working_title' => 'Something else',
            'primary_title' => 'B',
            'speaker_id' => $speaker->id,
        ]);

        // The global search covers the speaker too, so it finds both.
        $this->assertCount(2, $this->titles(['q' => 'Ahmad']));

        // The column filter means that column. Matching a speaker's name here
        // would read as a bug to anybody who had just typed into the Working
        // title box.
        $this->assertSame(['Ahmad lecture'], $this->titles(['working_title' => 'Ahmad']));
    }

    #[Test]
    public function the_updated_window_narrows_by_recency(): void
    {
        $this->project(['working_title' => 'Recent'], minutesAgo: 30);
        $this->project(['working_title' => 'Last week'], minutesAgo: 60 * 24 * 5);
        $this->project(['working_title' => 'Ancient'], minutesAgo: 60 * 24 * 90);

        $this->assertSame(['Recent'], $this->titles(['updated_within' => 'today']));
        $this->assertSame(['Recent', 'Last week'], $this->titles(['updated_within' => '7d']));
    }

    #[Test]
    public function an_unknown_updated_window_is_refused(): void
    {
        // Unlike a sort key, this one is worth rejecting: silently ignoring it
        // would show an unfiltered list to somebody who asked for a filtered
        // one, which looks like the filter is broken.
        $this->getJson('/api/v1/content-projects?updated_within=last_century')
            ->assertStatus(422);
    }

    // ── Query cost ──────────────────────────────────────────────────────────

    #[Test]
    public function a_full_page_costs_no_more_queries_than_a_single_row(): void
    {
        ContentProject::factory()->count(25)->create([
            'user_id' => $this->user->id,
            'topic_id' => ContentTopic::factory()->create(['user_id' => $this->user->id])->id,
            'speaker_id' => Speaker::factory()->create(['user_id' => $this->user->id])->id,
        ]);

        $count = function (int $perPage): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/content-projects?per_page={$perPage}")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $count(10);
        $many = $count(25);

        // Not an exact number — that would break on any framework change —
        // but the shape has to hold: relations and per-row state come from
        // eager loads and subqueries, so the cost is flat in the page size.
        // Before this sprint, each row asked for its own replacement status.
        $this->assertSame(
            $one,
            $many,
            'Query count grew with the page size, which means something is running per row.',
        );
    }
}
