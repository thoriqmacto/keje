<?php

namespace Tests\Feature\Studio;

use App\Enums\GoogleService;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use ArrayObject;
use Google\Client;
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
 * The connected-account catalog: channel, playlists, categories, uploads.
 *
 * Google is faked at the Guzzle layer, so the real normalization, caching and
 * scope checks all execute. Nothing here reaches the network.
 */
class GoogleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private ArrayObject $requests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = new ArrayObject;

        config([
            'services.google.clients.youtube.client_id' => 'youtube-client-id',
            'services.google.clients.youtube.client_secret' => 'youtube-secret',
            'services.google.clients.youtube.redirect_uri' => 'http://localhost:8000/api/v1/integrations/youtube/callback',
            'services.google.clients.drive.client_id' => 'drive-client-id',
            'services.google.clients.drive.client_secret' => 'drive-secret',
            'services.google.clients.drive.redirect_uri' => 'http://localhost:8000/api/v1/integrations/drive/callback',
            'services.youtube.region_code' => 'ID',
            'services.youtube.metadata_language' => 'id',
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param  list<string>|null  $scopes  null = the current full grant */
    private function connect(User $user, GoogleService $service, ?array $scopes = null): GoogleConnection
    {
        return GoogleConnection::create([
            'user_id' => $user->id,
            'service' => $service,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => $scopes ?? $service->scopes(),
            'connected_at' => now(),
        ]);
    }

    /** @param  list<Response>  $responses */
    private function fakeGoogle(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $http = new GuzzleClient(['handler' => $stack]);

        $this->app->bind(GoogleClientFactory::class, fn () => new class($http) extends GoogleClientFactory
        {
            public function __construct(private readonly GuzzleClient $http) {}

            public function base(GoogleService $service): Client
            {
                $client = parent::base($service);
                $client->setHttpClient($this->http);

                return $client;
            }
        });
    }

    private function jsonBody(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private function channelPayload(): array
    {
        return [
            'items' => [[
                'id' => 'UCGxBzjbs5jNmcdlb9WaTtVQ',
                'snippet' => [
                    'title' => 'Kajian Codingbox',
                    'description' => 'Lectures',
                    'customUrl' => '@kajiancodingbox',
                    'country' => 'ID',
                    'thumbnails' => ['high' => ['url' => 'https://yt3.example/high.jpg']],
                ],
                'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUGxBzjbs5jNmcdlb9WaTtVQ']],
                'statistics' => [
                    'viewCount' => '2435992',
                    'subscriberCount' => '12345',
                    'hiddenSubscriberCount' => false,
                    'videoCount' => '218',
                ],
                'status' => ['privacyStatus' => 'public', 'longUploadsStatus' => 'allowed'],
            ]],
        ];
    }

    // ── Scopes and capabilities ─────────────────────────────────────────────

    #[Test]
    public function the_youtube_flow_now_requests_playlist_management(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $url = urldecode((string) $this->postJson('/api/v1/integrations/youtube/redirect')
            ->assertOk()->json('data.authorization_url'));

        $this->assertStringContainsString('youtube.upload', $url);
        $this->assertStringContainsString('youtube.readonly', $url);
        $this->assertStringContainsString('youtube.force-ssl', $url);
    }

    #[Test]
    public function the_scope_split_survives_the_new_permission(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $youtube = urldecode((string) $this->postJson('/api/v1/integrations/youtube/redirect')
            ->json('data.authorization_url'));

        Sanctum::actingAs($user);
        $drive = urldecode((string) $this->postJson('/api/v1/integrations/drive/redirect')
            ->json('data.authorization_url'));

        // The reason the two clients exist at all.
        $this->assertStringNotContainsString('drive', $youtube);
        $this->assertStringNotContainsString('youtube', $drive);
        $this->assertStringContainsString('drive.file', $drive);

        foreach ([$youtube, $drive] as $url) {
            $this->assertStringNotContainsString('include_granted_scopes=true', $url);
        }
    }

    #[Test]
    public function a_grant_made_before_this_iteration_reports_no_playlist_capability(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        // Exactly what the previous iteration stored.
        $this->connect($user, GoogleService::YouTube, [
            GoogleService::SCOPE_YOUTUBE_UPLOAD,
            GoogleService::SCOPE_YOUTUBE_READONLY,
        ]);

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.capabilities.read_channel', true)
            ->assertJsonPath('data.youtube.capabilities.upload_video', true)
            // Uploads keep working; only playlist assignment is unavailable.
            ->assertJsonPath('data.youtube.capabilities.manage_playlists', false)
            ->assertJsonPath('data.youtube.needs_scope_upgrade', true);
    }

    #[Test]
    public function an_upgraded_grant_reports_playlist_capability(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.capabilities.manage_playlists', true)
            ->assertJsonPath('data.youtube.needs_scope_upgrade', false);
    }

    #[Test]
    public function capabilities_come_from_the_grant_not_from_configuration(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Configured, but nobody has connected.
        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.configured', true)
            ->assertJsonPath('data.youtube.capabilities.upload_video', false)
            ->assertJsonPath('data.drive.capabilities.backup', false);
    }

    // ── Channel profile ─────────────────────────────────────────────────────

    #[Test]
    public function the_channel_profile_is_normalised(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->fakeGoogle([$this->jsonBody($this->channelPayload())]);

        $this->getJson('/api/v1/integrations/youtube/channel')
            ->assertOk()
            ->assertJsonPath('data.channel_id', 'UCGxBzjbs5jNmcdlb9WaTtVQ')
            ->assertJsonPath('data.title', 'Kajian Codingbox')
            ->assertJsonPath('data.custom_url', '@kajiancodingbox')
            ->assertJsonPath('data.thumbnail_url', 'https://yt3.example/high.jpg')
            // Google sends counts as strings; the frontend needs numbers.
            ->assertJsonPath('data.subscriber_count', 12345)
            ->assertJsonPath('data.video_count', 218)
            ->assertJsonPath('data.uploads_playlist_id', 'UUGxBzjbs5jNmcdlb9WaTtVQ');
    }

    #[Test]
    public function no_google_token_is_ever_serialised_into_a_catalog_response(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->fakeGoogle([$this->jsonBody($this->channelPayload())]);

        $body = (string) json_encode($this->getJson('/api/v1/integrations/youtube/channel')->json());

        $this->assertStringNotContainsString('refresh', $body);
        $this->assertStringNotContainsString('youtube-secret', $body);
    }

    // ── Playlists ───────────────────────────────────────────────────────────

    #[Test]
    public function the_uploads_playlist_is_never_offered_as_a_destination(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->fakeGoogle([
            $this->jsonBody([
                'items' => [
                    [
                        'id' => 'PLriyadh',
                        'snippet' => ['title' => 'Riyadhush Shalihin'],
                        'contentDetails' => ['itemCount' => 23],
                        'status' => ['privacyStatus' => 'public'],
                    ],
                    [
                        // The channel's system uploads playlist. Google returns
                        // it, but playlistItems.insert against it fails.
                        'id' => 'UUGxBzjbs5jNmcdlb9WaTtVQ',
                        'snippet' => ['title' => 'Uploads from Kajian'],
                        'contentDetails' => ['itemCount' => 218],
                        'status' => ['privacyStatus' => 'public'],
                    ],
                ],
                'nextPageToken' => 'PAGE2',
            ]),
            $this->jsonBody($this->channelPayload()),
        ]);

        $response = $this->getJson('/api/v1/integrations/youtube/playlists')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'PLriyadh')
            ->assertJsonPath('data.0.title', 'Riyadhush Shalihin')
            ->assertJsonPath('data.0.item_count', 23)
            // Google's cursor is passed through, not faked as an offset.
            ->assertJsonPath('meta.next_page_token', 'PAGE2');

        $this->assertStringNotContainsString('UUGxBz', (string) json_encode($response->json('data')));
    }

    // ── Categories ──────────────────────────────────────────────────────────

    #[Test]
    public function only_assignable_categories_are_offered(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->fakeGoogle([
            $this->jsonBody(['items' => [
                ['id' => '27', 'snippet' => ['title' => 'Pendidikan', 'assignable' => true]],
                ['id' => '22', 'snippet' => ['title' => 'Orang & Blog', 'assignable' => true]],
                // Exists but cannot be set on an upload; offering it would
                // produce a rejected videos.insert.
                ['id' => '18', 'snippet' => ['title' => 'Short Movies', 'assignable' => false]],
            ]]),
        ]);

        $response = $this->getJson('/api/v1/integrations/youtube/categories')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(
            ['Orang & Blog', 'Pendidikan'],
            array_column($response->json('data'), 'title'),
        );
        $this->assertStringNotContainsString('Short Movies', (string) json_encode($response->json()));
    }

    #[Test]
    public function categories_are_requested_for_the_configured_region_and_language(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->fakeGoogle([$this->jsonBody(['items' => []])]);

        $this->getJson('/api/v1/integrations/youtube/categories')->assertOk();

        $uri = (string) $this->requests[0]['request']->getUri();
        $this->assertStringContainsString('regionCode=ID', $uri);
        $this->assertStringContainsString('hl=id', $uri);
    }

    // ── Recent uploads ──────────────────────────────────────────────────────

    #[Test]
    public function recent_uploads_come_from_the_uploads_playlist_not_search(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->fakeGoogle([
            $this->jsonBody($this->channelPayload()),
            $this->jsonBody(['items' => [[
                'snippet' => [
                    'title' => 'Keutamaan Lapar',
                    'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.example/1.jpg']],
                ],
                'contentDetails' => ['videoId' => 'dQw4w9WgXcQ', 'videoPublishedAt' => '2026-08-28T10:00:00Z'],
            ]]]),
        ]);

        $this->getJson('/api/v1/integrations/youtube/recent-uploads')
            ->assertOk()
            ->assertJsonPath('data.0.video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.0.title', 'Keutamaan Lapar')
            ->assertJsonPath('data.0.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        // search.list costs 100 quota units against a 10,000/day default.
        foreach ($this->requests as $entry) {
            $this->assertStringNotContainsString('/search', (string) $entry['request']->getUri());
        }
    }

    // ── Caching ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_second_read_is_served_from_cache(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        // One canned response for two requests: a second call to Google would
        // exhaust the queue and fail.
        $this->fakeGoogle([$this->jsonBody($this->channelPayload())]);

        $this->getJson('/api/v1/integrations/youtube/channel')->assertOk();
        $this->getJson('/api/v1/integrations/youtube/channel')->assertOk();

        $this->assertCount(1, $this->requests);
    }

    #[Test]
    public function refresh_drops_the_cache_and_reads_again(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->fakeGoogle([
            $this->jsonBody($this->channelPayload()),
            $this->jsonBody($this->channelPayload()),
        ]);

        $this->getJson('/api/v1/integrations/youtube/channel')->assertOk();
        $this->postJson('/api/v1/integrations/youtube/refresh')->assertOk();

        $this->assertCount(2, $this->requests);
    }

    // ── Failure handling ────────────────────────────────────────────────────

    #[Test]
    public function a_disconnected_service_is_reported_without_calling_google(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/youtube/channel')
            ->assertStatus(409)
            ->assertJsonPath('error', 'not_connected');

        $this->assertCount(0, $this->requests);
    }

    #[Test]
    public function a_quota_error_is_translated_and_does_not_disconnect_anyone(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->fakeGoogle([
            new Response(403, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => [
                    'code' => 403,
                    'message' => 'The request cannot be completed because you have exceeded your quota.',
                    'errors' => [['reason' => 'quotaExceeded', 'message' => 'quota']],
                ],
            ])),
        ]);

        $this->getJson('/api/v1/integrations/youtube/channel')
            ->assertStatus(502)
            ->assertJsonPath('error', 'google_error');

        // A quota problem is not an authorization problem.
        $this->assertNotNull($user->refresh()->googleConnectionFor(GoogleService::YouTube));
    }

    #[Test]
    public function a_catalog_failure_does_not_affect_the_status_endpoint(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->fakeGoogle([new Response(500, [], '{}')]);

        $this->getJson('/api/v1/integrations/youtube/playlists')->assertStatus(502);

        // The integrations page still renders: status is a local read.
        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.connected', true);
    }

    // ── Drive stays narrow ──────────────────────────────────────────────────

    #[Test]
    public function drive_about_is_normalised_and_scoped_to_drive_file(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive);

        $this->fakeGoogle([
            $this->jsonBody([
                'user' => ['displayName' => 'Thariq', 'emailAddress' => 'me@example.test'],
                'storageQuota' => ['limit' => '16106127360', 'usage' => '8804682956'],
            ]),
            $this->jsonBody(['files' => [[
                'id' => 'folder-1',
                'name' => 'Keje YouTube Outputs',
                'webViewLink' => 'https://drive.google.com/drive/folders/folder-1',
            ]]]),
        ]);

        $this->getJson('/api/v1/integrations/drive/about')
            ->assertOk()
            ->assertJsonPath('data.account.email', 'me@example.test')
            ->assertJsonPath('data.storage.limit', 16106127360)
            ->assertJsonPath('data.backup_folder.name', 'Keje YouTube Outputs')
            ->assertJsonPath('data.backup_folder_available', true);

        // Keje must never ask for more of a user's Drive than it created.
        $this->assertSame(
            [GoogleService::SCOPE_DRIVE_FILE],
            GoogleService::Drive->scopes(),
        );
    }

    #[Test]
    public function a_missing_backup_folder_is_reported_not_treated_as_a_disconnect(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive);

        $this->fakeGoogle([
            $this->jsonBody([
                'user' => ['emailAddress' => 'me@example.test'],
                'storageQuota' => ['limit' => '100', 'usage' => '1'],
            ]),
            $this->jsonBody(['files' => []]),
        ]);

        $this->getJson('/api/v1/integrations/drive/about')
            ->assertOk()
            ->assertJsonPath('data.backup_folder', null)
            ->assertJsonPath('data.backup_folder_available', false);

        $this->assertNotNull($user->refresh()->googleConnectionFor(GoogleService::Drive));
    }

    // ── Playlist destination precedence ─────────────────────────────────────

    private function project(User $user, array $overrides = []): ContentProject
    {
        return ContentProject::factory()->withMedia()->create(['user_id' => $user->id, ...$overrides]);
    }

    #[Test]
    public function a_project_override_beats_the_topics_playlist(): void
    {
        $user = User::factory()->create();
        $topic = ContentTopic::factory()->create([
            'user_id' => $user->id,
            'youtube_playlist_id' => 'PLtopic',
        ]);
        $project = $this->project($user, [
            'topic_id' => $topic->id,
            'youtube_metadata' => ['playlist_id' => 'PLoverride'],
        ]);

        $assigner = app(\App\Services\Google\YouTubePlaylistAssigner::class);

        $this->assertSame('PLoverride', $assigner->resolve($project->fresh(['topic'])));
    }

    #[Test]
    public function the_topics_playlist_is_used_when_the_project_does_not_override(): void
    {
        $user = User::factory()->create();
        $topic = ContentTopic::factory()->create([
            'user_id' => $user->id,
            'youtube_playlist_id' => 'PLtopic',
        ]);
        $project = $this->project($user, ['topic_id' => $topic->id, 'youtube_metadata' => []]);

        $assigner = app(\App\Services\Google\YouTubePlaylistAssigner::class);

        $this->assertSame('PLtopic', $assigner->resolve($project->fresh(['topic'])));
    }

    #[Test]
    public function no_playlist_is_attempted_when_neither_is_set(): void
    {
        $user = User::factory()->create();
        $topic = ContentTopic::factory()->create([
            'user_id' => $user->id,
            'youtube_playlist_id' => null,
        ]);
        $project = $this->project($user, ['topic_id' => $topic->id, 'youtube_metadata' => []]);

        $assigner = app(\App\Services\Google\YouTubePlaylistAssigner::class);

        $this->assertNull($assigner->resolve($project->fresh(['topic'])));
    }
}
