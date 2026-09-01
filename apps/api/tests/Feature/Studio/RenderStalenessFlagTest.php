<?php

namespace Tests\Feature\Studio;

use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staleness as a column you can filter on, and the churn that came with it.
 *
 * Two things this sprint had to get right, both of them the kind that look
 * fine until months later:
 *
 *  1. A persisted derived value must not drift from what it derives from.
 *     Staleness depends on the topic and speaker names, which are drawn on the
 *     video — so renaming a speaker invalidates renders belonging to projects
 *     nobody touched. A flag that misses that is worse than no flag, because
 *     it looks like an answer.
 *
 *  2. `updated_at` is what the list sorts by. If a background status poll
 *     bumps it, rows move on their own — and, worse, move between pages while
 *     somebody is reading them.
 */
class RenderStalenessFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /** A rendered project whose stored hash matches its current inputs. */
    private function rendered(array $attributes = []): ContentProject
    {
        $project = ContentProject::factory()->create([
            'user_id' => $this->user->id,
            ...$attributes,
        ]);

        $project->forceFill([
            'output_path' => "content/{$project->uuid}/renders/output.mp4",
            'last_render_input_hash' => app(RenderInputFingerprint::class)->for($project),
        ])->save();

        return $project->refresh();
    }

    // ── The flag itself ─────────────────────────────────────────────────────

    #[Test]
    public function a_fresh_render_is_not_stale(): void
    {
        $this->assertFalse($this->rendered()->render_is_stale);
    }

    #[Test]
    public function a_project_that_has_never_rendered_is_not_stale(): void
    {
        // Unknown, not outdated. Marking every historical project stale on the
        // day this shipped would be noise dressed up as information.
        $project = ContentProject::factory()->create(['user_id' => $this->user->id]);

        $this->assertFalse($project->render_is_stale);
    }

    #[Test]
    public function editing_drawn_text_marks_the_render_stale(): void
    {
        $project = $this->rendered();

        $project->update(['subtitle' => 'A subtitle typed after rendering']);

        $this->assertTrue($project->refresh()->render_is_stale);
    }

    #[Test]
    public function editing_the_working_title_does_not(): void
    {
        $project = $this->rendered();

        // Never drawn on the frame, so it cannot invalidate an encode. A
        // rename must not cost a two-hour render.
        $project->update(['working_title' => 'A better label for me']);

        $this->assertFalse($project->refresh()->render_is_stale);
    }

    // ── The cascade ─────────────────────────────────────────────────────────

    #[Test]
    public function renaming_a_speaker_marks_their_rendered_projects_stale(): void
    {
        $speaker = Speaker::factory()->create(['user_id' => $this->user->id, 'name' => 'Ahmad']);
        $project = $this->rendered(['speaker_id' => $speaker->id]);

        $this->assertFalse($project->render_is_stale);

        // The speaker's name is drawn on the video, so this genuinely
        // invalidates the render — and nothing re-saves the project, which is
        // exactly how a persisted flag goes quietly wrong.
        $speaker->update(['name' => 'Ahmad bin Yusuf']);

        $this->assertTrue($project->refresh()->render_is_stale);
    }

    #[Test]
    public function renaming_a_topic_marks_its_rendered_projects_stale(): void
    {
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id, 'name' => 'Kajian']);
        $project = $this->rendered(['topic_id' => $topic->id]);

        $topic->update(['name' => 'Kajian Tematik']);

        $this->assertTrue($project->refresh()->render_is_stale);
    }

    #[Test]
    public function the_cascade_leaves_unrendered_projects_alone(): void
    {
        $topic = ContentTopic::factory()->create(['user_id' => $this->user->id]);
        $draft = ContentProject::factory()->create([
            'user_id' => $this->user->id,
            'topic_id' => $topic->id,
        ]);

        $topic->update(['name' => 'Renamed']);

        // Nothing has been rendered, so there is nothing to be stale.
        $this->assertFalse($draft->refresh()->render_is_stale);
    }

    #[Test]
    public function the_cascade_does_not_reorder_the_studio_list(): void
    {
        $speaker = Speaker::factory()->create(['user_id' => $this->user->id]);
        $project = $this->rendered(['speaker_id' => $speaker->id]);

        $project->timestamps = false;
        $project->forceFill(['updated_at' => now()->subWeek()])->save();
        $project->timestamps = true;
        $before = $project->refresh()->updated_at;

        $speaker->update(['name' => 'Renamed']);

        // Renaming a speaker is an edit to the speaker, not to each of their
        // lectures. Bumping every one of them to the top of the list would
        // misrepresent what happened.
        $this->assertTrue($before->equalTo($project->refresh()->updated_at));
        $this->assertTrue($project->render_is_stale);
    }

    // ── Filtering on it ─────────────────────────────────────────────────────

    #[Test]
    public function the_outdated_filter_finds_exactly_the_stale_projects(): void
    {
        $current = $this->rendered(['working_title' => 'Current']);
        $stale = $this->rendered(['working_title' => 'Outdated']);
        $stale->update(['subtitle' => 'Changed after rendering']);

        $titles = array_column(
            $this->getJson('/api/v1/content-projects?render_status=outdated')
                ->assertOk()
                ->json('data'),
            'working_title',
        );

        $this->assertSame(['Outdated'], $titles);
        $this->assertFalse($current->refresh()->render_is_stale);
    }

    // ── The churn fix ───────────────────────────────────────────────────────

    #[Test]
    public function a_background_status_sync_does_not_bump_updated_at(): void
    {
        $project = ContentProject::factory()->create([
            'user_id' => $this->user->id,
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Published,
        ]);

        $project->timestamps = false;
        $project->forceFill(['updated_at' => now()->subMonth()])->save();
        $project->timestamps = true;
        $before = $project->refresh()->updated_at;

        // What the sync service does when it records a result.
        $project->forceFill(['youtube_remote_status' => 'published', 'youtube_remote_synced_at' => now()]);
        $project->timestamps = false;
        $project->save();
        $project->timestamps = true;

        // Noticing something about an already-published video is not a change
        // to the project. If it moved updated_at, rows would migrate between
        // pages under a reader for no visible reason.
        $this->assertTrue($before->equalTo($project->refresh()->updated_at));
        $this->assertSame('published', $project->youtube_remote_status);
    }
}
