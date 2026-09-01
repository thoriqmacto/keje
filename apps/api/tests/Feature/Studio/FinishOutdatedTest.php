<?php

namespace Tests\Feature\Studio;

use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Jobs\RenderContentProjectJob;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\User;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk re-rendering the outdated projects in a Studio view.
 *
 * Two properties matter more than the rest, and both are easy to get wrong in
 * ways that look fine in a demo:
 *
 *  1. **Scope is the filtered dataset, not the visible page.** A button that
 *     silently means "the twenty-five rows you can see" is a trap — you would
 *     have to page through pressing it, with no way to know when you were
 *     done.
 *
 *  2. **Nothing reaches YouTube.** Some of these projects have published
 *     videos. Replacing one costs its URL, its views and every comment, which
 *     is why that workflow is confirmed one at a time. A bulk button must not
 *     be a way around that.
 */
class FinishOutdatedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /**
     * A rendered project whose inputs have since changed.
     *
     * Built the way it happens in life: render, then edit. The observer marks
     * it stale on the edit, which is what the whole feature keys off.
     */
    private function outdated(array $attributes = [], ?User $owner = null): ContentProject
    {
        $project = ContentProject::factory()->withMedia()->create([
            'user_id' => ($owner ?? $this->user)->id,
            ...$attributes,
        ]);

        // The files the render would read, actually on the fake disk.
        Storage::disk('local')->put($project->source_audio_path, 'audio');
        Storage::disk('local')->put($project->background_image_path, 'image');

        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'last_render_input_hash' => app(RenderInputFingerprint::class)->for($project),
        ])->save();

        // Edited after rendering: now genuinely outdated.
        $project->update(['subtitle' => 'Corrected after the render '.$project->id]);

        return $project->refresh();
    }

    // ── Scope ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_plan_covers_the_whole_filtered_set_not_one_page(): void
    {
        foreach (range(1, 5) as $i) {
            $this->outdated(['working_title' => "Outdated {$i}"]);
        }

        // A page size of two would show two rows. The plan must still see all
        // five, or "Finish all" means "finish some".
        $this->getJson('/api/v1/content-projects/finish-plan?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.outdated', 5)
            ->assertJsonPath('data.eligible', 5);
    }

    #[Test]
    public function execution_queues_every_match_not_one_page(): void
    {
        Queue::fake();

        foreach (range(1, 5) as $i) {
            $this->outdated(['working_title' => "Outdated {$i}"]);
        }

        $this->postJson('/api/v1/content-projects/finish-all?per_page=2')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 5);

        Queue::assertPushed(RenderContentProjectJob::class, 5);
    }

    #[Test]
    public function a_topic_filter_leaves_other_topics_alone(): void
    {
        Queue::fake();

        $wanted = ContentTopic::factory()->create(['user_id' => $this->user->id]);
        $other = ContentTopic::factory()->create(['user_id' => $this->user->id]);

        $this->outdated(['working_title' => 'In scope', 'topic_id' => $wanted->id]);
        $this->outdated(['working_title' => 'Out of scope', 'topic_id' => $other->id]);

        $this->postJson("/api/v1/content-projects/finish-all?topic={$wanted->uuid}")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        Queue::assertPushed(RenderContentProjectJob::class, 1);
    }

    #[Test]
    public function a_current_render_is_never_touched(): void
    {
        Queue::fake();

        // Rendered and never edited since: nothing to do.
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $this->user->id]);
        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'last_render_input_hash' => app(RenderInputFingerprint::class)->for($project),
        ])->save();

        $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function another_users_outdated_project_is_never_included(): void
    {
        Queue::fake();

        $this->outdated(['working_title' => 'Theirs'], owner: User::factory()->create());

        $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0);

        Queue::assertNothingPushed();
    }

    // ── Skips and blocks ────────────────────────────────────────────────────

    #[Test]
    public function a_project_already_rendering_is_skipped_not_queued_twice(): void
    {
        Queue::fake();

        $project = $this->outdated();
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();

        $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.skipped', 1);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_project_whose_source_media_is_gone_is_blocked_with_a_reason(): void
    {
        Queue::fake();

        $project = $this->outdated(['working_title' => 'No audio']);
        // Pruned, or lost in a deploy: the column still points somewhere.
        Storage::disk('local')->delete($project->source_audio_path);

        $response = $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.blocked', 1);

        // "Blocked" alone is not actionable; the reason names the fix.
        $this->assertSame(
            'missing_source_file',
            $response->json('data.blocked_projects.0.reason_code'),
        );

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_plan_tallies_blocked_reasons_rather_than_just_counting(): void
    {
        $a = $this->outdated(['working_title' => 'A']);
        $b = $this->outdated(['working_title' => 'B']);
        Storage::disk('local')->delete($a->source_audio_path);
        Storage::disk('local')->delete($b->source_audio_path);

        $this->getJson('/api/v1/content-projects/finish-plan')
            ->assertOk()
            ->assertJsonPath('data.blocked', 2)
            ->assertJsonPath('data.blocked_reasons.missing_source_file', 2);
    }

    #[Test]
    public function one_blocked_project_does_not_abandon_the_rest(): void
    {
        Queue::fake();

        $this->outdated(['working_title' => 'Fine one']);
        $this->outdated(['working_title' => 'Fine two']);

        // Its own path, pointing at nothing. The factory's media fixture is
        // shared between projects, so deleting that file would break all
        // three rather than the one this case is about.
        $broken = $this->outdated(['working_title' => 'Broken']);
        $broken->forceFill([
            'source_audio_path' => "content/{$broken->uuid}/source/gone.mp3",
        ])->save();

        // Otherwise the outcome would depend on which project sorted first.
        $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.blocked', 1);

        Queue::assertPushed(RenderContentProjectJob::class, 2);
    }

    #[Test]
    public function a_project_corrected_between_plan_and_execute_is_skipped(): void
    {
        Queue::fake();

        $project = $this->outdated();

        // Someone re-rendered it by hand in the meantime, so the fingerprint
        // matches again. Acting on the earlier plan would burn an hour of
        // queue time producing the same file.
        $project->forceFill([
            'last_render_input_hash' => app(RenderInputFingerprint::class)->for($project),
        ])->save();

        $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0);

        Queue::assertNothingPushed();
    }

    // ── The safety property ─────────────────────────────────────────────────

    #[Test]
    public function a_published_project_is_re_rendered_and_youtube_is_never_touched(): void
    {
        Queue::fake();

        $project = $this->outdated([
            'working_title' => 'Already on YouTube',
            'youtube_video_id' => 'abc123',
            'youtube_url' => 'https://www.youtube.com/watch?v=abc123',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_uploaded_at' => now()->subWeek(),
        ]);

        $this->postJson('/api/v1/content-projects/finish-all')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        // The render is queued...
        Queue::assertPushed(RenderContentProjectJob::class, 1);

        /*
         * ...and nothing else is. This is the assertion the whole feature
         * hangs on: no upload, no replacement, no status sync. Replacing a
         * published video costs its URL, its views and every comment, and
         * that is a decision somebody makes one video at a time with a typed
         * confirmation — never a side effect of a bulk button.
         */
        Queue::assertNotPushed(\App\Jobs\UploadVideoToYouTubeJob::class);
        Queue::assertNotPushed(\App\Jobs\AdvanceYouTubeReplacementJob::class);
        Queue::assertNotPushed(\App\Jobs\UploadVideoToGoogleDriveJob::class);

        $project->refresh();

        // The published video is untouched and still current.
        $this->assertSame('abc123', $project->youtube_video_id);
        $this->assertSame(YouTubeStatus::Published, $project->youtube_status);
        $this->assertNull($project->activeYouTubeReplacement());
    }

    #[Test]
    public function the_queued_render_carries_no_post_actions(): void
    {
        Queue::fake();

        $project = $this->outdated([
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Published,
        ]);

        $this->postJson('/api/v1/content-projects/finish-all')->assertStatus(202);

        // Post-actions are how a render triggers an upload when it finishes.
        // Inferring them from "this was published once" is exactly how a bulk
        // re-render would turn into a bulk replacement.
        $this->assertNull($project->refresh()->latestRenderJob()?->post_actions);
    }
}
