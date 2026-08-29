<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentTopicsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/topics')->assertStatus(401);
        $this->postJson('/api/v1/topics', ['name' => 'x'])->assertStatus(401);
    }

    #[Test]
    public function a_topic_can_be_created_and_gets_a_slug(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/topics', ['name' => 'Riyadhush Shalihin'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Riyadhush Shalihin')
            ->assertJsonPath('data.slug', 'riyadhush-shalihin');

        $this->assertDatabaseHas('content_topics', [
            'user_id' => $user->id,
            'name' => 'Riyadhush Shalihin',
        ]);
    }

    #[Test]
    public function a_topic_requires_a_name(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/topics', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function the_index_only_lists_the_callers_topics(): void
    {
        $me = User::factory()->create();
        ContentTopic::factory()->count(2)->create(['user_id' => $me->id]);
        ContentTopic::factory()->count(3)->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs($me);

        $this->assertCount(2, $this->getJson('/api/v1/topics')->assertOk()->json('data'));
    }

    #[Test]
    public function another_users_topic_is_not_reachable(): void
    {
        $theirs = ContentTopic::factory()->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/topics/{$theirs->uuid}")->assertStatus(404);
        $this->patchJson("/api/v1/topics/{$theirs->uuid}", ['name' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/v1/topics/{$theirs->uuid}")->assertStatus(404);

        $this->assertDatabaseHas('content_topics', ['id' => $theirs->id, 'name' => $theirs->name]);
    }

    #[Test]
    public function topic_detail_lists_its_projects_in_sequence_order(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id]);

        foreach ([12, 10, 11] as $sequence) {
            ContentProject::factory()->create([
                'user_id' => $user->id,
                'topic_id' => $topic->id,
                'topic_sequence' => $sequence,
            ]);
        }

        $sequences = $this->getJson("/api/v1/topics/{$topic->uuid}")
            ->assertOk()
            ->json('data.projects.*.topic_sequence');

        $this->assertSame([10, 11, 12], $sequences);
    }

    #[Test]
    public function the_next_sequence_is_suggested_from_the_highest_used(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id]);

        ContentProject::factory()->create([
            'user_id' => $user->id,
            'topic_id' => $topic->id,
            'topic_sequence' => 11,
        ]);

        $this->getJson("/api/v1/topics/{$topic->uuid}")
            ->assertOk()
            ->assertJsonPath('data.next_sequence', 12);
    }

    #[Test]
    public function a_new_topic_suggests_sequence_one(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id]);

        $this->getJson("/api/v1/topics/{$topic->uuid}")
            ->assertOk()
            ->assertJsonPath('data.next_sequence', 1);
    }

    #[Test]
    public function a_playlist_id_can_be_linked_without_affecting_anything_else(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/topics/{$topic->uuid}", [
            'youtube_playlist_id' => 'PLtest123',
        ])
            ->assertOk()
            ->assertJsonPath('data.youtube_playlist_id', 'PLtest123');
    }

    #[Test]
    public function deleting_a_topic_ungroups_its_projects_rather_than_destroying_them(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $topic = ContentTopic::factory()->create(['user_id' => $user->id]);
        $project = ContentProject::factory()->create([
            'user_id' => $user->id,
            'topic_id' => $topic->id,
        ]);

        $this->deleteJson("/api/v1/topics/{$topic->uuid}")->assertNoContent();

        $this->assertDatabaseHas('content_projects', ['id' => $project->id, 'topic_id' => null]);
    }

    #[Test]
    public function slugs_only_need_to_be_unique_per_owner(): void
    {
        $other = User::factory()->create();
        ContentTopic::factory()->create(['user_id' => $other->id, 'slug' => 'riyadhush-shalihin']);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/topics', ['name' => 'Riyadhush Shalihin'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'riyadhush-shalihin');
    }
}
