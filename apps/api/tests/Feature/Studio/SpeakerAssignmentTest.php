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

/**
 * The Studio list showed "—" for every speaker.
 *
 * These walk the whole path the frontend actually takes — selector UUID,
 * request, resolveRelations, column, relation, resource, both endpoints — so
 * that a future break is located rather than guessed at. The plumbing was
 * never the fault: a project created without a speaker simply had no way to
 * gain one afterwards, which is what the editable properties card fixes.
 */
class SpeakerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_project_with_a_speaker_persists_the_foreign_key(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $speaker = Speaker::factory()->create(['user_id' => $user->id, 'name' => 'Syafiq Riza Basalamah']);

        // The selector sends the UUID, which is what SpeakerResource exposes
        // as `id`; the integer primary key never leaves the server.
        $this->postJson('/api/v1/content-projects', [
            'working_title' => 'Keutamaan Lapar',
            'speaker_id' => $speaker->uuid,
        ])->assertCreated()->assertJsonPath('data.speaker.name', 'Syafiq Riza Basalamah');

        $this->assertSame($speaker->id, ContentProject::first()->speaker_id);
    }

    #[Test]
    public function both_endpoints_the_studio_reads_include_the_speaker(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $speaker = Speaker::factory()->create(['user_id' => $user->id, 'name' => 'Syafiq Riza Basalamah']);
        $project = ContentProject::factory()->create([
            'user_id' => $user->id,
            'speaker_id' => $speaker->id,
        ]);

        // Detail — the project page.
        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.speaker.name', 'Syafiq Riza Basalamah');

        // Index — the Studio table, which reads through withRenderProgress().
        // That scope selects `table.*`, so speaker_id survives for the
        // relation; a narrower select here would silently break the join.
        $this->getJson('/api/v1/content-projects')
            ->assertOk()
            ->assertJsonPath('data.0.speaker.name', 'Syafiq Riza Basalamah');
    }

    #[Test]
    public function a_project_with_no_speaker_reports_null_rather_than_erroring(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id, 'speaker_id' => null]);

        // Both resources emit an explicit null, and the frontend's
        // `project.speaker?.name ?? "—"` renders the dash. That dash means
        // no speaker — not a broken lookup, which is what it looked like.
        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.speaker', null);

        $this->getJson('/api/v1/content-projects')
            ->assertOk()
            ->assertJsonPath('data.0.speaker', null);
    }

    #[Test]
    public function a_speakerless_project_can_be_given_one_afterwards(): void
    {
        // The actual fix for the reported symptom: the project was created
        // without a speaker and had no way to acquire one.
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id, 'speaker_id' => null]);
        $speaker = Speaker::factory()->create(['user_id' => $user->id, 'name' => 'Syafiq Riza Basalamah']);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'speaker_id' => $speaker->uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.speaker.name', 'Syafiq Riza Basalamah');

        $this->assertSame($speaker->id, $project->refresh()->speaker_id);
    }

    #[Test]
    public function the_grouping_fields_are_editable_after_creation(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);
        $topic = ContentTopic::factory()->create(['user_id' => $user->id, 'name' => 'Riyadhush Shalihin']);
        $speaker = Speaker::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'working_title' => 'Renamed',
            'topic_id' => $topic->uuid,
            'topic_sequence' => 11,
            'speaker_id' => $speaker->uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.working_title', 'Renamed')
            ->assertJsonPath('data.topic.name', 'Riyadhush Shalihin')
            ->assertJsonPath('data.topic_sequence', 11);
    }

    #[Test]
    public function another_users_speaker_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);
        $foreign = Speaker::factory()->create(['user_id' => User::factory()->create()->id]);

        // Validated by an ownership-scoped exists rule, so a foreign UUID is
        // rejected rather than silently resolving to null.
        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'speaker_id' => $foreign->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors(['speaker_id']);
    }

    #[Test]
    public function a_speaker_can_be_cleared(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $speaker = Speaker::factory()->create(['user_id' => $user->id]);
        $project = ContentProject::factory()->create([
            'user_id' => $user->id,
            'speaker_id' => $speaker->id,
        ]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", ['speaker_id' => null])
            ->assertOk();

        $this->assertNull($project->refresh()->speaker_id);
    }
}
