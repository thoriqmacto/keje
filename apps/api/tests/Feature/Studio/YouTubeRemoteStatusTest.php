<?php

namespace Tests\Feature\Studio;

use App\Enums\GoogleService;
use App\Enums\YouTubeRemoteStatus;
use App\Enums\YouTubeStatus;
use App\Jobs\SyncYouTubeVideoStatusJob;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\YouTubeVideoSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A project uploaded as scheduled stayed "Scheduled" forever, because the
 * state was captured when videos.insert returned and nothing ever asked again.
 *
 * Google is never called for real here.
 */
class YouTubeRemoteStatusTest extends TestCase
{
    use RefreshDatabase;

    private \ArrayObject $requests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = new \ArrayObject;

        config([
            'services.google.clients.youtube.client_id' => 'youtube-client-id',
            'services.google.clients.youtube.client_secret' => 'youtube-secret',
            'services.google.clients.youtube.redirect_uri' => 'http://localhost:8000/api/v1/integrations/youtube/callback',
        ]);
    }

    private function connect(User $user): void
    {
        GoogleConnection::create([
            'user_id' => $user->id,
            'service' => GoogleService::YouTube,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => GoogleService::YouTube->scopes(),
            'connected_at' => now(),
        ]);
    }

    /**
     * Override the client's transport, not the factory's constructor: the
     * real constructor reaches the network, which is how an earlier version
     * of this helper hung the suite.
     *
     * @param  list<Response>  $responses
     */
    private function fakeGoogle(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $http = new GuzzleClient(['handler' => $stack]);

        $this->app->bind(GoogleClientFactory::class, fn () => new class($http) extends GoogleClientFactory
        {
            public function __construct(private readonly GuzzleClient $http) {}

            public function base(GoogleService $service): \Google\Client
            {
                $client = parent::base($service);
                $client->setHttpClient($this->http);

                return $client;
            }
        });
    }

    private function video(array $status, string $id = 'abc123'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'items' => [[
                'id' => $id,
                'snippet' => ['title' => 'Keutamaan Lapar'],
                'status' => $status,
            ]],
        ]));
    }

    private function uploaded(User $user, array $attributes = []): ContentProject
    {
        return ContentProject::factory()->create([
            'user_id' => $user->id,
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Scheduled,
            ...$attributes,
        ]);
    }

    // ── The reported symptom ────────────────────────────────────────────────

    #[Test]
    public function a_scheduled_video_becomes_published_once_google_says_so(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user, ['youtube_publish_at' => now()->subHour()]);

        $this->fakeGoogle([$this->video(['privacyStatus' => 'public', 'uploadStatus' => 'processed'])]);

        app(YouTubeVideoSyncService::class)->sync($project);

        $project->refresh();
        $this->assertSame(YouTubeRemoteStatus::Published->value, $project->youtube_remote_status);
        // The pipeline value follows, so the Studio list stops saying Scheduled.
        $this->assertSame(YouTubeStatus::Published, $project->youtube_status);
    }

    #[Test]
    public function before_its_publish_time_it_is_still_scheduled(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user);

        $this->fakeGoogle([$this->video([
            'privacyStatus' => 'private',
            'uploadStatus' => 'processed',
            'publishAt' => now()->addDay()->toIso8601String(),
        ])]);

        app(YouTubeVideoSyncService::class)->sync($project);

        $this->assertSame(YouTubeRemoteStatus::Scheduled->value, $project->refresh()->youtube_remote_status);
    }

    // ── Manual changes on YouTube ───────────────────────────────────────────

    #[Test]
    public function a_video_made_private_on_youtube_is_reported_as_private(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user, ['youtube_status' => YouTubeStatus::Published]);

        $this->fakeGoogle([$this->video(['privacyStatus' => 'private', 'uploadStatus' => 'processed'])]);

        app(YouTubeVideoSyncService::class)->sync($project);

        // Keje reports what YouTube says; it never sets the privacy back.
        $this->assertSame(YouTubeRemoteStatus::Private->value, $project->refresh()->youtube_remote_status);
    }

    #[Test]
    public function unlisted_is_reported_as_unlisted(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user);

        $this->fakeGoogle([$this->video(['privacyStatus' => 'unlisted', 'uploadStatus' => 'processed'])]);

        app(YouTubeVideoSyncService::class)->sync($project);

        $this->assertSame(YouTubeRemoteStatus::Unlisted->value, $project->refresh()->youtube_remote_status);
    }

    #[Test]
    public function a_deleted_video_becomes_unavailable(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user);

        // Asked for, not returned.
        $this->fakeGoogle([new Response(200, ['Content-Type' => 'application/json'], json_encode(['items' => []]))]);

        app(YouTubeVideoSyncService::class)->sync($project);

        $this->assertSame(YouTubeRemoteStatus::Unavailable->value, $project->refresh()->youtube_remote_status);
    }

    #[Test]
    public function a_sync_failure_keeps_the_last_known_state(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user, [
            'youtube_remote_status' => YouTubeRemoteStatus::Published->value,
        ]);

        $this->fakeGoogle([new Response(403, [], json_encode([
            'error' => ['errors' => [['reason' => 'quotaExceeded']], 'message' => 'Quota exceeded'],
        ]))]);

        app(YouTubeVideoSyncService::class)->sync($project);

        $project->refresh();
        // A quota error is not evidence that the video changed.
        $this->assertSame(YouTubeRemoteStatus::Published->value, $project->youtube_remote_status);
        $this->assertNotNull($project->youtube_remote_sync_error);
    }

    // ── Quota discipline ────────────────────────────────────────────────────

    #[Test]
    public function the_studio_list_never_calls_youtube_synchronously(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);

        foreach (range(1, 20) as $i) {
            $this->uploaded($user, ['youtube_video_id' => "video-{$i}"]);
        }

        $this->getJson('/api/v1/content-projects')->assertOk();

        // Bounded background refresh, never a call per row.
        Queue::assertPushed(SyncYouTubeVideoStatusJob::class, 10);
    }

    #[Test]
    public function a_recently_synced_project_is_not_refreshed_again(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $this->uploaded($user, ['youtube_remote_synced_at' => now()]);

        $this->getJson('/api/v1/content-projects')->assertOk();

        Queue::assertNotPushed(SyncYouTubeVideoStatusJob::class);
    }

    #[Test]
    public function a_project_with_no_video_is_never_synced(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        ContentProject::factory()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/content-projects')->assertOk();

        Queue::assertNotPushed(SyncYouTubeVideoStatusJob::class);
    }

    // ── Manual refresh ──────────────────────────────────────────────────────

    #[Test]
    public function the_refresh_endpoint_updates_the_remote_state(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploaded($user);

        $this->fakeGoogle([$this->video(['privacyStatus' => 'public', 'uploadStatus' => 'processed'])]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/sync")
            ->assertOk()
            ->assertJsonPath('data.youtube.remote.status', 'published')
            ->assertJsonPath('data.youtube.remote.label', 'Published');
    }

    #[Test]
    public function refreshing_a_project_with_no_video_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/sync")
            ->assertStatus(422);
    }
}
