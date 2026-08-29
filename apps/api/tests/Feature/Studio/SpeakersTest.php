<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/speakers')->assertStatus(401);
        $this->postJson('/api/v1/speakers', ['name' => 'x'])->assertStatus(401);
    }

    #[Test]
    public function a_speaker_can_be_created(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/speakers', ['name' => 'Syafiq Riza Basalamah'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Syafiq Riza Basalamah');

        $this->assertDatabaseHas('speakers', [
            'user_id' => $user->id,
            'name' => 'Syafiq Riza Basalamah',
        ]);
    }

    #[Test]
    public function the_stored_name_keeps_its_natural_casing(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/speakers', ['name' => 'Syafiq Riza Basalamah'])->assertCreated();

        // Uppercasing belongs to the Kajian Tematik renderer, not the record.
        $this->assertSame(
            'Syafiq Riza Basalamah',
            Speaker::where('user_id', $user->id)->value('name'),
        );
    }

    #[Test]
    public function a_speaker_is_reusable_across_projects(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $speaker = Speaker::factory()->create(['user_id' => $user->id]);

        foreach (['Part One', 'Part Two'] as $title) {
            $this->postJson('/api/v1/content-projects', [
                'working_title' => $title,
                'speaker_id' => $speaker->uuid,
            ])->assertCreated()->assertJsonPath('data.speaker.id', $speaker->uuid);
        }

        $this->assertSame(2, ContentProject::where('speaker_id', $speaker->id)->count());
    }

    #[Test]
    public function another_users_speaker_is_not_reachable(): void
    {
        $theirs = Speaker::factory()->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/speakers/{$theirs->uuid}")->assertStatus(404);
        $this->patchJson("/api/v1/speakers/{$theirs->uuid}", ['name' => 'Hijacked'])->assertStatus(404);
    }

    #[Test]
    public function another_users_speaker_cannot_be_attached_to_a_project(): void
    {
        $theirs = Speaker::factory()->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/content-projects', [
            'working_title' => 'Borrowed speaker',
            'speaker_id' => $theirs->uuid,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['speaker_id']);
    }

    #[Test]
    public function a_display_name_overrides_what_is_rendered(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/speakers', [
            'name' => 'Syafiq Riza Basalamah',
            'display_name' => 'Syafiq Basalamah',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Syafiq Riza Basalamah')
            ->assertJsonPath('data.render_name', 'Syafiq Basalamah');
    }
}
