<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\User;
use App\Services\Google\PlaylistTopicResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * YouTube playlists as the canonical topic, with the local ContentTopic kept
 * as its shadow.
 *
 * The shadow carries what YouTube has no concept of — the name drawn on the
 * frame, the TEMA sequence, the link to historical projects — so the table
 * stays. What goes away is maintaining both by hand.
 */
class PlaylistTopicTest extends TestCase
{
    use RefreshDatabase;

    private PlaylistTopicResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(PlaylistTopicResolver::class);
    }

    #[Test]
    public function a_playlist_creates_its_topic_shadow_once(): void
    {
        $user = User::factory()->create();

        $first = $this->resolver->resolve($user, 'PL123', 'Riyadhush Shalihin');
        $second = $this->resolver->resolve($user, 'PL123', 'Riyadhush Shalihin');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContentTopic::where('user_id', $user->id)->count());
        $this->assertSame('Riyadhush Shalihin', $first->name);
    }

    #[Test]
    public function renaming_the_playlist_renames_the_shadow(): void
    {
        // The playlist is the source of truth; someone renamed it there.
        $user = User::factory()->create();
        $topic = $this->resolver->resolve($user, 'PL123', 'Riyadush Shalihin');

        $renamed = $this->resolver->resolve($user, 'PL123', 'Riyadhush Shalihin');

        $this->assertSame($topic->id, $renamed->id);
        $this->assertSame('Riyadhush Shalihin', $renamed->fresh()->name);
    }

    #[Test]
    public function identity_is_the_playlist_id_never_the_name(): void
    {
        // Two playlists that happen to share a title are not the same topic.
        $user = User::factory()->create();

        $a = $this->resolver->resolve($user, 'PL_A', 'Kajian');
        $b = $this->resolver->resolve($user, 'PL_B', 'Kajian');

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function a_legacy_topic_with_the_same_name_is_adopted_not_duplicated(): void
    {
        // A topic created before playlists existed, which this playlist was
        // plainly made for. Adopting it keeps its history and its projects.
        $user = User::factory()->create();
        $legacy = ContentTopic::factory()->create([
            'user_id' => $user->id,
            'name' => 'Riyadhush Shalihin',
            'youtube_playlist_id' => null,
        ]);
        $project = ContentProject::factory()->create([
            'user_id' => $user->id,
            'topic_id' => $legacy->id,
        ]);

        $resolved = $this->resolver->resolve($user, 'PL123', 'Riyadhush Shalihin');

        $this->assertSame($legacy->id, $resolved->id);
        $this->assertSame('PL123', $resolved->fresh()->youtube_playlist_id);
        // The historical project keeps its topic.
        $this->assertSame($legacy->id, $project->fresh()->topic_id);
        $this->assertSame(1, ContentTopic::where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_topic_already_mapped_elsewhere_is_never_stolen(): void
    {
        $user = User::factory()->create();
        ContentTopic::factory()->create([
            'user_id' => $user->id,
            'name' => 'Kajian',
            'youtube_playlist_id' => 'PL_OTHER',
        ]);

        $resolved = $this->resolver->resolve($user, 'PL_NEW', 'Kajian');

        // Same name, different playlist: a separate topic.
        $this->assertSame('PL_NEW', $resolved->youtube_playlist_id);
        $this->assertSame(2, ContentTopic::where('user_id', $user->id)->count());
    }

    #[Test]
    public function topics_never_cross_between_users(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        ContentTopic::factory()->create([
            'user_id' => $theirs->id,
            'name' => 'Riyadhush Shalihin',
            'youtube_playlist_id' => 'PL123',
        ]);

        $resolved = $this->resolver->resolve($mine, 'PL123', 'Riyadhush Shalihin');

        $this->assertSame($mine->id, $resolved->user_id);
        $this->assertSame(2, ContentTopic::count());
    }

    #[Test]
    public function the_endpoint_returns_the_resolved_topic(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/topics/from-playlist', [
            'youtube_playlist_id' => 'PL123',
            'title' => 'Riyadhush Shalihin',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Riyadhush Shalihin')
            ->assertJsonPath('data.youtube_playlist_id', 'PL123');
    }

    #[Test]
    public function the_endpoint_needs_a_playlist_id(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/topics/from-playlist', ['title' => 'Nameless'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['youtube_playlist_id']);
    }

    #[Test]
    public function legacy_topics_without_a_playlist_remain_readable(): void
    {
        // Nothing is destroyed by this migration: historical projects keep
        // rendering with the topic name they were made with.
        Sanctum::actingAs($user = User::factory()->create());
        $legacy = ContentTopic::factory()->create([
            'user_id' => $user->id,
            'name' => 'An old topic',
            'youtube_playlist_id' => null,
        ]);

        $this->getJson('/api/v1/topics')
            ->assertOk()
            ->assertJsonFragment(['name' => 'An old topic']);

        $this->getJson("/api/v1/topics/{$legacy->uuid}")->assertOk();
    }
}
