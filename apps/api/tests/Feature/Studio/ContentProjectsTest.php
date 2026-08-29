<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentProjectsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/content-projects')->assertStatus(401);
        $this->postJson('/api/v1/content-projects', ['working_title' => 'x'])->assertStatus(401);
    }

    #[Test]
    public function a_project_can_be_created(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/content-projects', ['working_title' => 'Kajian 11'])
            ->assertCreated()
            ->assertJsonPath('data.working_title', 'Kajian 11')
            ->assertJsonPath('data.render.status', 'draft')
            ->assertJsonPath('data.drive.status', 'pending')
            ->assertJsonPath('data.youtube.status', 'pending');

        $this->assertDatabaseHas('content_projects', [
            'user_id' => $user->id,
            'working_title' => 'Kajian 11',
        ]);
    }

    #[Test]
    public function a_project_defaults_to_the_kajian_tematik_template(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/content-projects', ['working_title' => 'Kajian 11'])
            ->assertCreated()
            ->assertJsonPath('data.template_key', 'kajian-tematik');
    }

    #[Test]
    public function a_project_can_be_grouped_by_topic_and_speaker(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id]);
        $speaker = Speaker::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/content-projects', [
            'working_title' => 'Kajian 11',
            'topic_id' => $topic->uuid,
            'topic_sequence' => 11,
            'speaker_id' => $speaker->uuid,
            'primary_title' => 'Keutamaan Lapar, Hidup',
            'subtitle' => 'Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu',
            'part_number' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.topic.id', $topic->uuid)
            ->assertJsonPath('data.topic.sequence', 11)
            ->assertJsonPath('data.speaker.id', $speaker->uuid)
            ->assertJsonPath('data.part_number', 3);
    }

    #[Test]
    public function a_project_requires_a_working_title(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/content-projects', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['working_title']);
    }

    #[Test]
    public function the_index_only_lists_the_callers_projects(): void
    {
        $me = User::factory()->create();
        ContentProject::factory()->count(2)->create(['user_id' => $me->id]);
        ContentProject::factory()->count(3)->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs($me);

        $this->assertCount(2, $this->getJson('/api/v1/content-projects')->assertOk()->json('data'));
    }

    #[Test]
    public function another_users_project_is_not_reachable(): void
    {
        $theirs = ContentProject::factory()->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/content-projects/{$theirs->uuid}")->assertStatus(404);
        $this->patchJson("/api/v1/content-projects/{$theirs->uuid}", ['working_title' => 'Hijacked'])
            ->assertStatus(404);
        $this->deleteJson("/api/v1/content-projects/{$theirs->uuid}")->assertStatus(404);
        $this->getJson("/api/v1/content-projects/{$theirs->uuid}/preview")->assertStatus(404);

        $this->assertDatabaseHas('content_projects', ['id' => $theirs->id]);
    }

    #[Test]
    public function a_project_can_be_updated(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'working_title' => 'Renamed',
            'primary_title' => 'Sabar',
            'part_number' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.working_title', 'Renamed')
            ->assertJsonPath('data.primary_title', 'Sabar')
            ->assertJsonPath('data.part_number', 5);
    }

    #[Test]
    public function a_project_response_never_exposes_filesystem_paths(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $body = $this->getJson("/api/v1/content-projects/{$project->uuid}")->assertOk()->json();
        $encoded = json_encode($body);

        $this->assertStringNotContainsString('source_audio_path', $encoded);
        $this->assertStringNotContainsString('content/fixture', $encoded);
        $this->assertStringNotContainsString(storage_path(), $encoded);
    }

    #[Test]
    public function the_project_reports_detected_audio_metadata(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertOk()
            // JSON has no float/int distinction, so a whole-second duration
            // arrives as 1800, not 1800.0.
            ->assertJsonPath('data.source_audio.duration', 1800)
            ->assertJsonPath('data.source_audio.codec', 'mp3')
            ->assertJsonPath('data.source_audio.sample_rate', 44100)
            ->assertJsonPath('data.source_audio.original_name', 'lecture.mp3');
    }

    #[Test]
    public function an_unknown_template_key_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/content-projects', [
            'working_title' => 'Kajian 11',
            'template_key' => '../../etc/passwd',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template_key']);
    }

    #[Test]
    public function the_visual_title_and_the_youtube_title_are_separate_fields(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'primary_title' => 'Keutamaan Lapar, Hidup',
            'youtube_metadata' => [
                'title' => 'Keutamaan Lapar, Hidup Sederhana | Riyadhush Shalihin #11 | Part 3',
                'privacy_status' => 'private',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.primary_title', 'Keutamaan Lapar, Hidup')
            ->assertJsonPath(
                'data.youtube.metadata.title',
                'Keutamaan Lapar, Hidup Sederhana | Riyadhush Shalihin #11 | Part 3',
            );
    }

    #[Test]
    public function a_youtube_title_over_one_hundred_characters_is_rejected(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'youtube_metadata' => ['title' => str_repeat('a', 101)],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['youtube_metadata.title']);
    }

    #[Test]
    public function a_publish_date_in_the_past_is_rejected(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'youtube_metadata' => ['publish_at' => now()->subDay()->toIso8601String()],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['youtube_metadata.publish_at']);
    }

    #[Test]
    public function a_project_can_be_deleted(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->deleteJson("/api/v1/content-projects/{$project->uuid}")->assertNoContent();

        $this->assertDatabaseMissing('content_projects', ['id' => $project->id]);
    }

    // ── Preview / layout contract ───────────────────────────────────────────

    #[Test]
    public function the_preview_returns_the_resolved_layout(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id, 'name' => 'Riyadhush Shalihin']);
        $speaker = Speaker::factory()->create(['user_id' => $user->id, 'name' => 'Syafiq Riza Basalamah']);

        $project = ContentProject::factory()->create([
            'user_id' => $user->id,
            'topic_id' => $topic->id,
            'topic_sequence' => 11,
            'speaker_id' => $speaker->id,
        ]);

        $layout = $this->getJson("/api/v1/content-projects/{$project->uuid}/preview")
            ->assertOk()
            ->json('data');

        $this->assertSame(1280, $layout['canvas']['width']);
        $this->assertSame(720, $layout['canvas']['height']);

        $keys = array_column($layout['elements'], 'key');

        foreach ([
            'topic', 'topic_sequence', 'speaker_label', 'speaker_name',
            'branding', 'primary_title', 'subtitle_line_1', 'part', 'waveform',
        ] as $expected) {
            $this->assertContains($expected, $keys, "Layout is missing {$expected}");
        }
    }

    #[Test]
    public function the_preview_rejects_a_title_that_cannot_fit(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create([
            'user_id' => $user->id,
            'primary_title' => str_repeat('Keutamaan Lapar Hidup Sederhana ', 8),
        ]);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/preview")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_title']);
    }
}
