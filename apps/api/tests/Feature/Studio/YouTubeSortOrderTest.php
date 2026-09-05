<?php

namespace Tests\Feature\Studio;

use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sorting the Studio list by YouTube.
 *
 * The column used to sort by the raw enum string, which is alphabetical —
 * failed, pending, published, scheduled, uploaded, uploading — an order that
 * answers no question anybody has. It also had no second key, so two projects
 * sharing a status came back in whatever order the database felt like.
 *
 * Ascending now means "least far along first", and within a rung the date the
 * project actually has decides. These tests pin both halves, and the one that
 * matters most is the last: a published video must never be ordered by the
 * stale plan its metadata still carries.
 */
class YouTubeSortOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /** @param array<string, mixed> $attributes */
    private function project(string $title, array $attributes = []): ContentProject
    {
        return ContentProject::factory()->for($this->user)->create([
            'working_title' => $title,
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function titlesSortedBy(string $direction): array
    {
        return $this->getJson("/api/v1/content-projects?sort=youtube_status&direction={$direction}&per_page=50")
            ->assertOk()
            ->json('data.*.working_title');
    }

    #[Test]
    public function ascending_runs_from_nothing_decided_to_live(): void
    {
        // One project per rung, created out of order so nothing can pass by
        // accident of insertion.
        $this->project('e-scheduled', [
            'youtube_status' => YouTubeStatus::Scheduled,
            'youtube_video_id' => 'VID5',
            'youtube_publish_at' => Carbon::parse('2027-01-01T09:00:00Z'),
        ]);
        $this->project('a-not-planned', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['title' => 'No schedule at all'],
        ]);
        $this->project('f-published', [
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'VID6',
            'youtube_uploaded_at' => Carbon::parse('2026-06-01T09:00:00Z'),
        ]);
        $this->project('b-planned', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-01-01T09:00:00Z'],
        ]);
        $this->project('d-uploaded', [
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'VID4',
            'youtube_uploaded_at' => Carbon::parse('2026-06-01T09:00:00Z'),
        ]);
        $this->project('c-failed', [
            'youtube_status' => YouTubeStatus::Failed,
            'youtube_error' => 'Quota exceeded',
        ]);

        $this->assertSame(
            ['a-not-planned', 'b-planned', 'c-failed', 'd-uploaded', 'e-scheduled', 'f-published'],
            $this->titlesSortedBy('asc'),
        );
    }

    #[Test]
    public function descending_is_the_exact_reverse(): void
    {
        $this->project('a-not-planned', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['title' => 'No schedule at all'],
        ]);
        $this->project('b-planned', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-01-01T09:00:00Z'],
        ]);
        $this->project('c-scheduled', [
            'youtube_status' => YouTubeStatus::Scheduled,
            'youtube_video_id' => 'VID3',
            'youtube_publish_at' => Carbon::parse('2027-01-01T09:00:00Z'),
        ]);
        $this->project('d-published', [
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'VID4',
            'youtube_uploaded_at' => Carbon::parse('2026-06-01T09:00:00Z'),
        ]);

        $this->assertSame(
            ['d-published', 'c-scheduled', 'b-planned', 'a-not-planned'],
            $this->titlesSortedBy('desc'),
        );
    }

    #[Test]
    public function planned_projects_are_ordered_by_the_date_they_are_planned_for(): void
    {
        // The half of the ask that the old sort could not do at all: the
        // planned time lived in a JSON blob and nothing could order by it.
        $this->project('march', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-03-01T09:00:00Z'],
        ]);
        $this->project('january', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-01-01T09:00:00Z'],
        ]);
        $this->project('february', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-02-01T09:00:00Z'],
        ]);

        $this->assertSame(['january', 'february', 'march'], $this->titlesSortedBy('asc'));
        $this->assertSame(['march', 'february', 'january'], $this->titlesSortedBy('desc'));
    }

    #[Test]
    public function scheduled_projects_are_ordered_by_their_confirmed_publish_time(): void
    {
        foreach (['march' => '2027-03-01', 'january' => '2027-01-01', 'february' => '2027-02-01'] as $title => $date) {
            $this->project($title, [
                'youtube_status' => YouTubeStatus::Scheduled,
                'youtube_video_id' => strtoupper($title),
                'youtube_publish_at' => Carbon::parse("{$date}T09:00:00Z"),
            ]);
        }

        $this->assertSame(['january', 'february', 'march'], $this->titlesSortedBy('asc'));
    }

    #[Test]
    public function a_published_video_is_never_ordered_by_a_plan_it_has_already_passed(): void
    {
        /*
         * The subtle one, and the reason the COALESCE reads uploaded_at before
         * the plan. Metadata keeps its publish_at after the upload, so a video
         * published in June that still carries a plan for next March would
         * sort as though it were the furthest-off thing on the channel.
         */
        $this->project('published-june', [
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'JUNE',
            'youtube_uploaded_at' => Carbon::parse('2026-06-01T09:00:00Z'),
            // A leftover plan, far in the future.
            'youtube_metadata' => ['publish_at' => '2027-03-01T09:00:00Z'],
        ]);
        $this->project('published-july', [
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'JULY',
            'youtube_uploaded_at' => Carbon::parse('2026-07-01T09:00:00Z'),
        ]);

        $this->assertSame(['published-june', 'published-july'], $this->titlesSortedBy('asc'));
    }

    #[Test]
    public function projects_with_no_date_at_all_still_page_deterministically(): void
    {
        // Rung 0 has no date by definition, so the id tie-breaker is the only
        // thing keeping rows from swapping places between pages.
        foreach (range(1, 5) as $n) {
            $this->project("none-{$n}", [
                'youtube_status' => YouTubeStatus::Pending,
                'youtube_metadata' => null,
            ]);
        }

        $first = $this->titlesSortedBy('asc');

        $this->assertSame($first, $this->titlesSortedBy('asc'));
        $this->assertCount(5, $first);
    }

    #[Test]
    public function the_planned_column_tracks_the_metadata_it_is_derived_from(): void
    {
        $project = $this->project('tracks', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-01-01T09:00:00Z'],
        ]);

        $this->assertTrue(
            $project->fresh()->youtube_planned_publish_at->equalTo(Carbon::parse('2027-01-01T09:00:00Z')),
        );

        // Clearing the schedule has to clear the column too, or the project
        // would keep sorting as "planned" with nothing planned.
        $project->update(['youtube_metadata' => ['title' => 'Schedule removed']]);

        $this->assertNull($project->fresh()->youtube_planned_publish_at);
    }

    #[Test]
    public function the_migration_backfills_projects_that_existed_before_the_column(): void
    {
        /*
         * Everything above proves the observer keeps new saves in step. This
         * proves the other half: a project last saved before the column
         * existed has to sort correctly without anyone opening and re-saving
         * it, or the first thing this feature does on real data is get the
         * order wrong for every project already there.
         *
         * Written straight through the query builder, which is what an old row
         * looks like: no model event has ever touched this column.
         */
        $id = DB::table('content_projects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'working_title' => 'Saved before the column existed',
            'slug' => 'saved-before-the-column-existed',
            'template_key' => 'kajian-tematik',
            'render_status' => 'draft',
            'drive_status' => 'pending',
            'youtube_status' => 'pending',
            'youtube_metadata' => json_encode(['publish_at' => '2027-05-01T09:00:00Z']),
            'youtube_planned_publish_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('content_projects')->where('id', $id)->value('youtube_planned_publish_at'));

        // The add is guarded by hasColumn, so re-running only backfills.
        (require base_path('database/migrations/2026_09_05_090000_add_youtube_planned_publish_at_to_content_projects.php'))->up();

        $this->assertTrue(
            Carbon::parse(DB::table('content_projects')->where('id', $id)->value('youtube_planned_publish_at'))
                ->equalTo(Carbon::parse('2027-05-01T09:00:00Z')),
        );
    }

    #[Test]
    public function the_backfill_skips_a_value_it_cannot_parse(): void
    {
        // A hand-edited row must not abort the migration for every project
        // after it.
        $id = DB::table('content_projects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'working_title' => 'Nonsense schedule',
            'slug' => 'nonsense-schedule',
            'template_key' => 'kajian-tematik',
            'render_status' => 'draft',
            'drive_status' => 'pending',
            'youtube_status' => 'pending',
            'youtube_metadata' => json_encode(['publish_at' => 'whenever']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require base_path('database/migrations/2026_09_05_090000_add_youtube_planned_publish_at_to_content_projects.php'))->up();

        $this->assertNull(DB::table('content_projects')->where('id', $id)->value('youtube_planned_publish_at'));
    }

    #[Test]
    public function a_time_zone_offset_is_stored_as_the_instant_it_names(): void
    {
        // The form always writes UTC, but the API accepts any parseable date.
        // 09:00+07:00 is 02:00Z, and it has to sort as 02:00Z.
        $this->project('jakarta-morning', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-01-01T09:00:00+07:00'],
        ]);
        $this->project('utc-early', [
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => '2027-01-01T01:00:00Z'],
        ]);

        $this->assertSame(['utc-early', 'jakarta-morning'], $this->titlesSortedBy('asc'));
    }
}
