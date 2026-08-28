<?php

namespace Tests\Feature\Notes;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/notes')->assertStatus(401);
        $this->postJson('/api/v1/notes', ['title' => 'x'])->assertStatus(401);
    }

    public function test_index_returns_only_the_callers_notes(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        Note::factory()->for($me)->count(2)->create();
        Note::factory()->for($other)->count(3)->create();

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/notes')->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_store_creates_a_note_owned_by_the_caller(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs($me);

        $this->postJson('/api/v1/notes', [
            'title' => 'Pick up milk',
            'body' => 'Lactose free',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Pick up milk')
            ->assertJsonPath('data.body', 'Lactose free');

        $this->assertDatabaseHas('notes', [
            'user_id' => $me->id,
            'title' => 'Pick up milk',
        ]);
    }

    public function test_store_requires_a_title(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/notes', ['body' => 'no title'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_destroy_deletes_own_note(): void
    {
        $me = User::factory()->create();
        $note = Note::factory()->for($me)->create();

        Sanctum::actingAs($me);

        $this->deleteJson("/api/v1/notes/{$note->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_destroy_refuses_to_delete_someone_elses_note(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $their = Note::factory()->for($other)->create();

        Sanctum::actingAs($me);

        $this->deleteJson("/api/v1/notes/{$their->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('notes', ['id' => $their->id]);
    }
}
