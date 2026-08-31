<?php

namespace Tests\Feature\Studio;

use App\Enums\GoogleService;
use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Models\YouTubePublication;
use App\Services\Google\GoogleClientFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcting a published video's metadata, without touching the video.
 *
 * The case that must never turn into a re-upload. A wrong description is not a
 * reason to lose a URL, the view count and every comment — YouTube edits all of
 * that in place, and these tests hold the line by asserting on the actual HTTP
 * verbs and paths: a videos.insert here would be a real duplicate lecture on a
 * real channel.
 *
 * Google is never called for real.
 */
class YouTubeMetadataUpdateTest extends TestCase
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
            'services.youtube.default_category_id' => '27',
        ]);
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = []): GoogleConnection
    {
        return GoogleConnection::create([
            'user_id' => $user->id,
            'service' => GoogleService::YouTube,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => $scopes === [] ? GoogleService::YouTube->scopes() : $scopes,
            'connected_at' => now(),
        ]);
    }

    /**
     * Replace the transport, not the factory's constructor — the real one
     * reaches the network.
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

    /** A videos.list response carrying every field a complete update needs. */
    private function remoteVideo(array $overrides = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'items' => [[
                'id' => 'abc123',
                'snippet' => array_merge([
                    'title' => 'Original title',
                    'description' => 'Original description',
                    'categoryId' => '27',
                    'tags' => ['kajian'],
                    'defaultLanguage' => 'id',
                ], $overrides['snippet'] ?? []),
                'status' => array_merge([
                    'privacyStatus' => 'public',
                    'uploadStatus' => 'processed',
                    'license' => 'youtube',
                    'selfDeclaredMadeForKids' => false,
                ], $overrides['status'] ?? []),
            ]],
        ]));
    }

    private function updated(array $status = [], array $snippet = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'abc123',
            'snippet' => array_merge(['title' => 'Corrected title', 'categoryId' => '27'], $snippet),
            'status' => array_merge(['privacyStatus' => 'public'], $status),
        ]));
    }

    private function published(User $user, array $attributes = []): ContentProject
    {
        return ContentProject::factory()->create([
            'user_id' => $user->id,
            'youtube_video_id' => 'abc123',
            'youtube_url' => 'https://www.youtube.com/watch?v=abc123',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_uploaded_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    /** Every request the fake transport saw, as "METHOD /path". */
    private function calls(): array
    {
        return array_map(
            static fn (array $t): string => $t['request']->getMethod().' '.$t['request']->getUri()->getPath(),
            iterator_to_array($this->requests),
        );
    }

    // ── The metadata-only path ──────────────────────────────────────────────

    #[Test]
    public function editing_the_description_updates_the_same_video(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published($user);

        // videos.list (read for a complete update), videos.update, then the
        // sync read that confirms what landed.
        $this->fakeGoogle([
            $this->remoteVideo(),
            $this->updated(),
            $this->remoteVideo(),
        ]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'A corrected description',
        ])->assertOk();

        $project->refresh();

        // The identity that must survive a metadata correction.
        $this->assertSame('abc123', $project->youtube_video_id);
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $project->youtube_url);
        $this->assertSame('A corrected description', $project->youtube_metadata['description']);
    }

    #[Test]
    public function a_metadata_correction_never_uploads_or_deletes(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published($user);

        $this->fakeGoogle([$this->remoteVideo(), $this->updated(), $this->remoteVideo()]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'Corrected',
        ])->assertOk();

        $calls = $this->calls();

        // The whole point. An upload here would be a second real lecture on a
        // real channel, and a delete would destroy the one that is there.
        $this->assertEmpty(
            array_filter($calls, static fn (string $c): bool => str_contains($c, 'upload')),
            'A metadata correction must never reach videos.insert.',
        );
        $this->assertEmpty(
            array_filter($calls, static fn (string $c): bool => str_starts_with($c, 'DELETE')),
            'A metadata correction must never delete the video.',
        );
        $this->assertNotEmpty(
            array_filter($calls, static fn (string $c): bool => str_starts_with($c, 'PUT')),
            'videos.update is a PUT; the correction should have issued one.',
        );
    }

    #[Test]
    public function the_update_sends_a_complete_snippet_so_nothing_is_erased(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published($user, [
            'youtube_metadata' => ['title' => 'Corrected title', 'category_id' => '22'],
        ]);

        $this->fakeGoogle([$this->remoteVideo(), $this->updated(), $this->remoteVideo()]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'Only the description changed',
        ])->assertOk();

        $put = null;

        foreach ($this->requests as $transaction) {
            if ($transaction['request']->getMethod() === 'PUT') {
                $put = json_decode((string) $transaction['request']->getBody(), true);
            }
        }

        $this->assertNotNull($put, 'Expected a videos.update request.');

        /*
         * videos.update replaces each part it is given rather than patching it.
         * A snippet carrying only the description would blank the title and the
         * category — and YouTube requires both, so the call would fail outright.
         * These four assertions are the guard against that.
         */
        $this->assertSame('Corrected title', $put['snippet']['title']);
        $this->assertSame('22', $put['snippet']['categoryId']);
        $this->assertSame('Only the description changed', $put['snippet']['description']);
        // Carried over from the remote read: not a field Keje manages, and so
        // not one it may destroy.
        $this->assertSame('youtube', $put['status']['license']);
    }

    #[Test]
    public function privacy_can_be_corrected_in_place(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published($user);

        $this->fakeGoogle([
            $this->remoteVideo(),
            $this->updated(['privacyStatus' => 'unlisted']),
            $this->remoteVideo(['status' => ['privacyStatus' => 'unlisted']]),
        ]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'privacy_status' => 'unlisted',
        ])->assertOk();

        $this->assertSame('unlisted', $project->refresh()->youtube_metadata['privacy_status']);
    }

    #[Test]
    public function the_history_snapshot_follows_the_correction(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published($user);

        $this->fakeGoogle([$this->remoteVideo(), $this->updated(), $this->remoteVideo()]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'title' => 'Corrected title',
        ])->assertOk();

        // Backfilled on the way through, because this project was published
        // before publication history existed.
        $publication = YouTubePublication::where('content_project_id', $project->id)->firstOrFail();

        $this->assertSame('abc123', $publication->youtube_video_id);
        $this->assertSame('Corrected title', $publication->title);
        $this->assertTrue($publication->isCurrent());
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    #[Test]
    public function a_project_with_no_video_cannot_be_corrected(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'Nothing to correct',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_connection_without_force_ssl_cannot_edit_videos(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        // The grant a connection made before force-ssl was requested holds. It
        // can upload perfectly well and cannot edit what it uploaded.
        $this->connect($user, [
            GoogleService::SCOPE_YOUTUBE_UPLOAD,
            GoogleService::SCOPE_YOUTUBE_READONLY,
        ]);
        $project = $this->published($user);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'Corrected',
        ])->assertStatus(422)->assertJsonPath(
            'errors.youtube.0',
            'Reconnect YouTube to allow Keje to edit videos it has already uploaded.',
        );

        $this->assertSame([], $this->calls(), 'Nothing should reach Google without the scope.');
    }

    #[Test]
    public function a_video_deleted_from_youtube_studio_reports_rather_than_retries(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published($user);

        // videos.list answering with nothing: the video is gone.
        $this->fakeGoogle([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['items' => []])),
        ]);

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'Corrected',
        ])->assertStatus(422);

        // No PUT: there is nothing to update, and retrying cannot help.
        $this->assertEmpty(array_filter(
            $this->calls(),
            static fn (string $c): bool => str_starts_with($c, 'PUT'),
        ));
    }

    #[Test]
    public function another_users_project_is_not_reachable(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->published(User::factory()->create());

        $this->patchJson("/api/v1/content-projects/{$project->uuid}/youtube/metadata", [
            'description' => 'Corrected',
        ])->assertNotFound();
    }
}
