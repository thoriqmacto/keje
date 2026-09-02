<?php

namespace Tests\Feature\Studio;

use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\User;
use App\Services\Google\YouTubeMetadataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The schedule a project intends, before YouTube has heard of it.
 *
 * Two different facts have to stay apart here, and collapsing them is the
 * failure these tests exist to prevent:
 *
 *   youtube_publish_at              what YouTube confirmed. Only written once
 *                                   an upload has succeeded. A promise.
 *
 *   youtube_metadata.publish_at     what somebody typed into the form. The
 *                                   only record of a schedule for the whole
 *                                   time a project sits in the render queue.
 *
 * The list previously exposed only the first, so a project scheduled a week
 * ago and still queued reported nothing but "Pending" — the publication date
 * was decided, stored, and invisible.
 *
 * The dangerous direction is the other one, though: metadata keeps its
 * publish_at forever, so a naive fallback would have every published video
 * advertising a publication that already happened.
 */
class PlannedPublishAtTest extends TestCase
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
    private function project(array $attributes = []): ContentProject
    {
        return ContentProject::factory()->for($this->user)->create($attributes);
    }

    #[Test]
    public function a_queued_project_reports_the_schedule_it_intends(): void
    {
        $this->project([
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_publish_at' => null,
            'youtube_metadata' => ['publish_at' => '2026-12-01T09:00:00+00:00'],
        ]);

        $row = $this->getJson('/api/v1/content-projects')->assertOk()->json('data.0');

        $this->assertNull($row['youtube']['scheduled_at']);
        $this->assertSame(
            Carbon::parse('2026-12-01T09:00:00+00:00')->toIso8601String(),
            $row['youtube']['planned_publish_at'],
        );
    }

    #[Test]
    public function a_project_with_no_schedule_reports_none(): void
    {
        $this->project([
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_publish_at' => null,
            'youtube_metadata' => ['title' => 'No schedule here'],
        ]);

        $row = $this->getJson('/api/v1/content-projects')->assertOk()->json('data.0');

        $this->assertNull($row['youtube']['planned_publish_at']);
    }

    #[Test]
    public function a_video_already_on_youtube_never_advertises_a_plan(): void
    {
        // The case worth the whole distinction. Metadata keeps publish_at
        // after the upload, so falling back to it unconditionally would have a
        // live video claiming a publication that has already happened.
        foreach ([YouTubeStatus::Uploaded, YouTubeStatus::Scheduled, YouTubeStatus::Published] as $status) {
            $project = $this->project([
                'youtube_status' => $status,
                'youtube_video_id' => 'VIDEO123',
                'youtube_publish_at' => null,
                'youtube_metadata' => ['publish_at' => '2026-12-01T09:00:00+00:00'],
            ]);

            $row = $this->getJson('/api/v1/content-projects')
                ->assertOk()
                ->json('data.0');

            $this->assertNull(
                $row['youtube']['planned_publish_at'],
                "{$status->value} should not report a planned publication",
            );

            $project->delete();
        }
    }

    #[Test]
    public function the_confirmed_schedule_is_still_reported_in_its_own_field(): void
    {
        // The existing contract, unchanged: what YouTube holds keeps its own
        // key, so nothing reading scheduled_at has to learn a new rule.
        $this->project([
            'youtube_status' => YouTubeStatus::Scheduled,
            'youtube_video_id' => 'VIDEO123',
            'youtube_publish_at' => Carbon::parse('2026-12-01T09:00:00+00:00'),
            'youtube_metadata' => ['publish_at' => '2026-12-01T09:00:00+00:00'],
        ]);

        $row = $this->getJson('/api/v1/content-projects')->assertOk()->json('data.0');

        $this->assertSame(
            Carbon::parse('2026-12-01T09:00:00+00:00')->toIso8601String(),
            $row['youtube']['scheduled_at'],
        );
        $this->assertNull($row['youtube']['planned_publish_at']);
    }

    #[Test]
    public function the_project_detail_reports_the_plan_too(): void
    {
        // Both screens answer "when does this go live" the same way, rather
        // than the detail page leaving the client to dig through `metadata`.
        $project = $this->project([
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_publish_at' => null,
            'youtube_metadata' => ['publish_at' => '2026-12-01T09:00:00+00:00'],
        ]);

        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath(
                'data.youtube.planned_publish_at',
                Carbon::parse('2026-12-01T09:00:00+00:00')->toIso8601String(),
            );
    }

    #[Test]
    public function a_plan_in_the_past_is_still_reported(): void
    {
        // It is not hidden: an unusable schedule is exactly the thing somebody
        // needs to see, because the upload refuses it. Deciding what to say
        // about it belongs to the interface, not to the wire format.
        $this->project([
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_publish_at' => null,
            'youtube_metadata' => ['publish_at' => '2020-01-01T09:00:00+00:00'],
        ]);

        $row = $this->getJson('/api/v1/content-projects')->assertOk()->json('data.0');

        $this->assertSame(
            Carbon::parse('2020-01-01T09:00:00+00:00')->toIso8601String(),
            $row['youtube']['planned_publish_at'],
        );
    }

    #[Test]
    public function unparseable_stored_metadata_does_not_break_the_list(): void
    {
        // Validated on the way in, so this means a hand-edited row. One bad
        // value must not take down every project on the page.
        $this->project([
            'youtube_status' => YouTubeStatus::Pending,
            'youtube_metadata' => ['publish_at' => 'whenever'],
        ]);

        $row = $this->getJson('/api/v1/content-projects')->assertOk()->json('data.0');

        $this->assertNull($row['youtube']['planned_publish_at']);
    }

    #[Test]
    public function the_upload_reads_the_same_plan_the_list_shows(): void
    {
        // The list and the upload must not each parse the intended schedule
        // their own way — that is how a row comes to promise one time while
        // the job asks YouTube for another.
        $project = $this->project([
            'youtube_metadata' => ['publish_at' => '2026-12-01T09:00:00+00:00'],
        ]);

        $intended = app(YouTubeMetadataBuilder::class)->for($project)['publish_at'];

        $this->assertNotNull($intended);
        $this->assertTrue($intended->equalTo($project->plannedPublishAt()));
    }
}
