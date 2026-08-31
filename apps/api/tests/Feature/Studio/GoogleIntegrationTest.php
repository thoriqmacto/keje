<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\GoogleService;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Jobs\UploadVideoToGoogleDriveJob;
use App\Jobs\UploadVideoToYouTubeJob;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveService;
use App\Services\Google\GoogleNotConnectedException;
use App\Services\Google\GoogleOAuthService;
use App\Services\Google\YouTubeMetadataBuilder;
use App\Services\Google\YouTubePlaylistAssigner;
use App\Services\Google\YouTubeService;
use App\Services\Media\MediaRetention;
use ArrayObject;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Google integration, with YouTube and Drive authorized independently.
 *
 * No test here reaches Google. Where the real OAuth logic matters the network
 * is faked at the Guzzle layer, so state handling, persistence and the
 * service isolation are genuinely exercised; elsewhere the services are
 * mocked outright.
 */
class GoogleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Requests the faked Google HTTP client saw, for isolation assertions. */
    private ArrayObject $googleRequests;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->googleRequests = new ArrayObject;

        config([
            'services.google.clients.youtube.client_id' => 'youtube-client-id',
            'services.google.clients.youtube.client_secret' => 'youtube-client-secret',
            'services.google.clients.youtube.redirect_uri' => 'http://localhost:8000/api/v1/integrations/youtube/callback',
            'services.google.clients.drive.client_id' => 'drive-client-id',
            'services.google.clients.drive.client_secret' => 'drive-client-secret',
            'services.google.clients.drive.redirect_uri' => 'http://localhost:8000/api/v1/integrations/drive/callback',
            'services.youtube.expected_channel_id' => 'UCGxBzjbs5jNmcdlb9WaTtVQ',
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function connect(User $user, GoogleService $service, array $overrides = []): GoogleConnection
    {
        $youtubeFields = $service === GoogleService::YouTube
            ? ['youtube_channel_id' => 'UCGxBzjbs5jNmcdlb9WaTtVQ', 'youtube_channel_title' => 'Test Channel']
            : [];

        return GoogleConnection::create([
            'user_id' => $user->id,
            'service' => $service,
            'access_token' => "access-token-{$service->value}",
            'refresh_token' => "refresh-token-{$service->value}",
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            ...$youtubeFields,
            ...$overrides,
        ]);
    }

    /**
     * Replace Google's HTTP transport with a queue of canned responses, while
     * leaving every line of our own OAuth code running for real.
     *
     * @param  list<Response>  $responses
     */
    private function fakeGoogleHttp(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->googleRequests));
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

    private function tokenResponse(GoogleService $service): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => "fresh-access-{$service->value}",
            'refresh_token' => "fresh-refresh-{$service->value}",
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => implode(' ', $service->scopes()),
        ]));
    }

    private function channelResponse(string $id = 'UCGxBzjbs5jNmcdlb9WaTtVQ'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'items' => [['id' => $id, 'snippet' => ['title' => 'Test Channel']]],
        ]));
    }

    /** Start a real consent flow and return the issued state. */
    private function issueState(User $user, GoogleService $service): string
    {
        Sanctum::actingAs($user);

        $url = $this->postJson("/api/v1/integrations/{$service->value}/redirect")
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function renderedProject(User $user): ContentProject
    {
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $path = "content/{$project->uuid}/renders/output.mp4";
        Storage::disk('local')->put($path, 'fake-mp4');

        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $path,
            'output_size' => 9,
        ])->save();

        return $project;
    }

    // ── Scope isolation: the bug this refactor exists to fix ────────────────

    #[Test]
    public function the_youtube_authorization_url_requests_only_youtube_scopes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $url = urldecode((string) $this->postJson('/api/v1/integrations/youtube/redirect')
            ->assertOk()
            ->json('data.authorization_url'));

        $this->assertStringContainsString('youtube.upload', $url);
        $this->assertStringContainsString('youtube.readonly', $url);

        // Google rejects a request carrying both products' scopes:
        // "scopes that cannot be requested together".
        $this->assertStringNotContainsString('drive.file', $url);
        $this->assertStringNotContainsString('auth/drive', $url);
    }

    #[Test]
    public function the_drive_authorization_url_requests_only_drive_scope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $url = urldecode((string) $this->postJson('/api/v1/integrations/drive/redirect')
            ->assertOk()
            ->json('data.authorization_url'));

        $this->assertStringContainsString('drive.file', $url);

        $this->assertStringNotContainsString('youtube.upload', $url);
        $this->assertStringNotContainsString('youtube.readonly', $url);
        $this->assertStringNotContainsString('youtube', $url);
    }

    #[Test]
    public function neither_flow_enables_incremental_scope_combining(): void
    {
        $user = User::factory()->create();

        foreach (GoogleService::cases() as $service) {
            Sanctum::actingAs($user);

            $url = urldecode((string) $this->postJson("/api/v1/integrations/{$service->value}/redirect")
                ->assertOk()
                ->json('data.authorization_url'));

            // include_granted_scopes lets Google fold previously granted
            // project scopes back in, which is exactly how a Drive consent
            // would silently acquire the YouTube scopes and be rejected.
            $this->assertStringNotContainsString('include_granted_scopes=true', $url, $service->value);
        }
    }

    #[Test]
    public function both_flows_request_offline_access_for_the_queue_workers(): void
    {
        $user = User::factory()->create();

        foreach (GoogleService::cases() as $service) {
            Sanctum::actingAs($user);

            $url = (string) $this->postJson("/api/v1/integrations/{$service->value}/redirect")
                ->assertOk()
                ->json('data.authorization_url');

            // Without offline access Google never issues a refresh token, and
            // background jobs cannot upload later.
            $this->assertStringContainsString('access_type=offline', $url, $service->value);
            $this->assertStringContainsString('prompt=consent', $url, $service->value);
        }
    }

    #[Test]
    public function each_flow_uses_its_own_oauth_client_and_redirect_uri(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $youtube = urldecode((string) $this->postJson('/api/v1/integrations/youtube/redirect')
            ->json('data.authorization_url'));

        Sanctum::actingAs($user);
        $drive = urldecode((string) $this->postJson('/api/v1/integrations/drive/redirect')
            ->json('data.authorization_url'));

        $this->assertStringContainsString('youtube-client-id', $youtube);
        $this->assertStringContainsString('/integrations/youtube/callback', $youtube);

        $this->assertStringContainsString('drive-client-id', $drive);
        $this->assertStringContainsString('/integrations/drive/callback', $drive);
    }

    // ── OAuth state ─────────────────────────────────────────────────────────

    #[Test]
    public function state_is_single_use(): void
    {
        $user = User::factory()->create();
        $state = $this->issueState($user, GoogleService::YouTube);

        $oauth = app(GoogleOAuthService::class);

        $this->assertTrue($oauth->consumeState(GoogleService::YouTube, $state)?->is($user));
        // A replayed callback must not resolve to the user again.
        $this->assertNull($oauth->consumeState(GoogleService::YouTube, $state));
    }

    #[Test]
    public function a_state_issued_for_one_service_is_rejected_by_the_other(): void
    {
        $user = User::factory()->create();
        $youtubeState = $this->issueState($user, GoogleService::YouTube);

        $oauth = app(GoogleOAuthService::class);

        // Otherwise a Drive consent could be redeemed as a YouTube connection.
        $this->assertNull($oauth->consumeState(GoogleService::Drive, $youtubeState));
        // And the real owner still works, proving it was not merely consumed.
        $this->assertTrue($oauth->consumeState(GoogleService::YouTube, $youtubeState)?->is($user));
    }

    #[Test]
    public function each_callback_rejects_a_missing_or_unknown_state(): void
    {
        foreach (['youtube', 'drive'] as $service) {
            $this->get("/api/v1/integrations/{$service}/callback?code=abc")
                ->assertRedirectContains("{$service}=invalid");

            $this->get("/api/v1/integrations/{$service}/callback?code=abc&state=forged")
                ->assertRedirectContains("{$service}=invalid_state");

            $this->get("/api/v1/integrations/{$service}/callback?error=access_denied")
                ->assertRedirectContains("{$service}=denied");
        }
    }

    #[Test]
    public function a_drive_state_presented_to_the_youtube_callback_is_refused(): void
    {
        $user = User::factory()->create();
        $driveState = $this->issueState($user, GoogleService::Drive);

        $this->get("/api/v1/integrations/youtube/callback?code=abc&state={$driveState}")
            ->assertRedirectContains('youtube=invalid_state');

        $this->assertDatabaseCount('google_connections', 0);
    }

    // ── Callbacks ───────────────────────────────────────────────────────────

    #[Test]
    public function the_youtube_callback_saves_a_youtube_connection_and_verifies_the_channel(): void
    {
        $user = User::factory()->create();
        $this->fakeGoogleHttp([
            $this->tokenResponse(GoogleService::YouTube),
            $this->channelResponse(),
        ]);

        $state = $this->issueState($user, GoogleService::YouTube);

        $this->get("/api/v1/integrations/youtube/callback?code=auth-code&state={$state}")
            ->assertRedirectContains('youtube=connected');

        $connection = $user->refresh()->googleConnectionFor(GoogleService::YouTube);

        $this->assertNotNull($connection);
        $this->assertSame(GoogleService::YouTube, $connection->service);
        $this->assertSame('fresh-refresh-youtube', $connection->refresh_token);
        $this->assertSame('UCGxBzjbs5jNmcdlb9WaTtVQ', $connection->youtube_channel_id);
        $this->assertSame('Test Channel', $connection->youtube_channel_title);
        $this->assertTrue($connection->matchesExpectedChannel());

        // The other service is untouched.
        $this->assertNull($user->googleConnectionFor(GoogleService::Drive));
    }

    #[Test]
    public function the_drive_callback_saves_a_drive_connection(): void
    {
        $user = User::factory()->create();
        $this->fakeGoogleHttp([$this->tokenResponse(GoogleService::Drive)]);

        $state = $this->issueState($user, GoogleService::Drive);

        $this->get("/api/v1/integrations/drive/callback?code=auth-code&state={$state}")
            ->assertRedirectContains('drive=connected');

        $connection = $user->refresh()->googleConnectionFor(GoogleService::Drive);

        $this->assertNotNull($connection);
        $this->assertSame(GoogleService::Drive, $connection->service);
        $this->assertSame('fresh-refresh-drive', $connection->refresh_token);
        $this->assertNull($user->googleConnectionFor(GoogleService::YouTube));
    }

    #[Test]
    public function the_drive_callback_never_calls_the_youtube_api(): void
    {
        $user = User::factory()->create();
        // Exactly one canned response: a second Google call would blow up the
        // MockHandler, and the request log below proves none was attempted.
        $this->fakeGoogleHttp([$this->tokenResponse(GoogleService::Drive)]);

        $state = $this->issueState($user, GoogleService::Drive);
        $this->get("/api/v1/integrations/drive/callback?code=auth-code&state={$state}")
            ->assertRedirectContains('drive=connected');

        $this->assertCount(1, $this->googleRequests, 'Drive connect made more than the token call.');

        foreach ($this->googleRequests as $entry) {
            $this->assertStringNotContainsString(
                'youtube',
                (string) $entry['request']->getUri(),
                'The Drive flow must never touch the YouTube API.',
            );
        }
    }

    #[Test]
    public function a_drive_connection_is_never_channel_verified(): void
    {
        $user = User::factory()->create();
        // A Drive row cannot be judged against the expected channel, even if
        // it somehow carried a channel id.
        $connection = $this->connect($user, GoogleService::Drive, [
            'youtube_channel_id' => 'UCsomeoneElsesChannel',
        ]);

        $this->assertNull($connection->matchesExpectedChannel());
    }

    // ── Status endpoint ─────────────────────────────────────────────────────

    #[Test]
    public function the_status_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/integrations/google')->assertStatus(401);
    }

    #[Test]
    public function the_status_endpoint_reports_both_services_independently(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.connected', true)
            ->assertJsonPath('data.youtube.configured', true)
            ->assertJsonPath('data.youtube.channel_matches_expected', true)
            ->assertJsonPath('data.drive.connected', false)
            ->assertJsonPath('data.drive.configured', true);
    }

    #[Test]
    public function a_different_channel_is_reported_as_a_mismatch(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube, ['youtube_channel_id' => 'UCsomeoneElsesChannel']);

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.channel_matches_expected', false);
    }

    #[Test]
    public function each_service_reports_its_own_configuration(): void
    {
        config(['services.google.clients.drive.client_id' => null]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.youtube.configured', true)
            ->assertJsonPath('data.drive.configured', false);
    }

    #[Test]
    public function connecting_an_unconfigured_service_is_refused(): void
    {
        config(['services.google.clients.drive.client_secret' => null]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/integrations/drive/redirect')->assertStatus(422);
        // The configured service still works.
        $this->postJson('/api/v1/integrations/youtube/redirect')->assertOk();
    }

    // ── Token safety ────────────────────────────────────────────────────────

    #[Test]
    public function tokens_are_never_exposed_through_the_api(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->connect($user, GoogleService::Drive);

        $body = (string) json_encode($this->getJson('/api/v1/integrations/google')->assertOk()->json());

        $this->assertStringNotContainsString('refresh-token', $body);
        $this->assertStringNotContainsString('access-token', $body);
        $this->assertStringNotContainsString('youtube-client-secret', $body);
        $this->assertStringNotContainsString('drive-client-secret', $body);
    }

    #[Test]
    public function tokens_are_encrypted_at_rest_for_both_services(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube);
        $this->connect($user, GoogleService::Drive);

        foreach (GoogleService::cases() as $service) {
            $raw = DB::table('google_connections')
                ->where('user_id', $user->id)
                ->where('service', $service->value)
                ->first();

            // The ciphertext must not contain the plaintext.
            $this->assertStringNotContainsString("refresh-token-{$service->value}", $raw->refresh_token);
            $this->assertStringNotContainsString("access-token-{$service->value}", $raw->access_token);

            $this->assertSame(
                "refresh-token-{$service->value}",
                $user->googleConnectionFor($service)->refresh_token,
            );
        }
    }

    // ── Independence ────────────────────────────────────────────────────────

    #[Test]
    public function disconnecting_drive_leaves_youtube_connected(): void
    {
        $this->fakeGoogleHttp([new Response(200, [], '{}')]);
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->connect($user, GoogleService::Drive);

        $this->deleteJson('/api/v1/integrations/drive')->assertOk();

        $this->assertNull($user->refresh()->googleConnectionFor(GoogleService::Drive));
        $this->assertNotNull($user->googleConnectionFor(GoogleService::YouTube));
    }

    #[Test]
    public function disconnecting_youtube_leaves_drive_connected(): void
    {
        $this->fakeGoogleHttp([new Response(200, [], '{}')]);
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $this->connect($user, GoogleService::Drive);

        $this->deleteJson('/api/v1/integrations/youtube')->assertOk();

        $this->assertNull($user->refresh()->googleConnectionFor(GoogleService::YouTube));
        $this->assertNotNull($user->googleConnectionFor(GoogleService::Drive));
    }

    #[Test]
    public function a_drive_backup_is_allowed_without_a_youtube_connection(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")->assertStatus(202);

        Queue::assertPushed(UploadVideoToGoogleDriveJob::class);
    }

    #[Test]
    public function a_youtube_upload_is_allowed_without_a_drive_connection(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")->assertStatus(202);

        Queue::assertPushed(UploadVideoToYouTubeJob::class);
    }

    #[Test]
    public function a_drive_backup_is_refused_when_only_youtube_is_connected(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['google']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_youtube_upload_is_refused_when_only_drive_is_connected(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['google']);

        Queue::assertNothingPushed();
    }

    // ── Jobs use their own credentials ──────────────────────────────────────

    #[Test]
    public function the_drive_upload_asks_for_drive_credentials(): void
    {
        $user = User::factory()->create();
        $path = Storage::disk('local')->path('probe.mp4');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'x');

        $clients = Mockery::mock(GoogleClientFactory::class);
        $clients->shouldReceive('forUser')
            ->once()
            ->with(Mockery::type(User::class), GoogleService::Drive)
            ->andThrow(new GoogleNotConnectedException('stop here'));

        $this->expectException(GoogleNotConnectedException::class);

        (new GoogleDriveService($clients))->upload($user, $path, 'out.mp4');
    }

    #[Test]
    public function the_youtube_upload_asks_for_youtube_credentials(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);
        $path = Storage::disk('local')->path($project->output_path);

        $clients = Mockery::mock(GoogleClientFactory::class);
        $clients->shouldReceive('forUser')
            ->once()
            ->with(Mockery::type(User::class), GoogleService::YouTube)
            ->andThrow(new GoogleNotConnectedException('stop here'));

        $this->expectException(GoogleNotConnectedException::class);

        (new YouTubeService($clients, app(YouTubeMetadataBuilder::class)))->upload($user->refresh(), $project, $path);
    }

    // ── Pipelines stay independent ──────────────────────────────────────────

    #[Test]
    public function a_drive_backup_is_refused_before_rendering(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive);
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['render']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_successful_drive_upload_records_the_file_details(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderedProject($user);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('upload')->once()->andReturn([
            'id' => 'drive-file-123',
            'name' => 'kajian.mp4',
            'web_view_link' => 'https://drive.google.com/file/d/drive-file-123/view',
        ]);

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive, app(MediaRetention::class));

        $project->refresh();
        $this->assertSame(DriveStatus::Uploaded, $project->drive_status);
        $this->assertSame('drive-file-123', $project->drive_file_id);
        $this->assertNotNull($project->drive_uploaded_at);
    }

    #[Test]
    public function a_failed_drive_upload_leaves_the_render_and_youtube_intact(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderedProject($user);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('upload')->once()
            ->andThrow(new GoogleNotConnectedException('Please reconnect Google Drive.'));

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive, app(MediaRetention::class));

        $project->refresh();
        $this->assertSame(DriveStatus::Failed, $project->drive_status);
        // The whole point of separate pipelines.
        $this->assertSame(RenderStatus::Rendered, $project->render_status);
        $this->assertSame(YouTubeStatus::Pending, $project->youtube_status);
        $this->assertNotNull($project->output_path);
    }

    #[Test]
    public function an_already_uploaded_project_is_not_uploaded_to_drive_twice(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderedProject($user);
        $project->forceFill([
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'existing-id',
        ])->save();

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldNotReceive('upload');

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive, app(MediaRetention::class));
    }

    #[Test]
    public function a_duplicate_youtube_upload_is_refused(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);
        $project->forceFill([
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'abc123',
        ])->save();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")->assertStatus(409);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_successful_upload_records_the_video_id_and_url(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('upload')->once()->andReturn([
            'id' => 'dQw4w9WgXcQ',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'privacy_status' => 'private',
            'publish_at' => null,
        ]);

        (new UploadVideoToYouTubeJob($project->id))->handle($youtube, app(MediaRetention::class), app(YouTubePlaylistAssigner::class));

        $project->refresh();
        $this->assertSame(YouTubeStatus::Uploaded, $project->youtube_status);
        $this->assertSame('dQw4w9WgXcQ', $project->youtube_video_id);
    }

    #[Test]
    public function a_scheduled_upload_is_recorded_as_scheduled(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('upload')->once()->andReturn([
            'id' => 'vid123',
            'url' => 'https://www.youtube.com/watch?v=vid123',
            'privacy_status' => 'private',
            'publish_at' => now()->addWeek()->toIso8601String(),
        ]);

        (new UploadVideoToYouTubeJob($project->id))->handle($youtube, app(MediaRetention::class), app(YouTubePlaylistAssigner::class));

        $this->assertSame(YouTubeStatus::Scheduled, $project->refresh()->youtube_status);
    }

    #[Test]
    public function a_publish_time_in_the_past_is_rejected(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube", [
            'publish_at' => now()->subHour()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['publish_at']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_upload_to_an_unexpected_channel_is_blocked(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube, ['youtube_channel_id' => 'UCwrongChannel']);

        $service = new YouTubeService(app(GoogleClientFactory::class), app(YouTubeMetadataBuilder::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not the expected channel');

        $service->assertExpectedChannel($user->refresh());
    }

    #[Test]
    public function an_upload_to_the_expected_channel_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube);

        $service = new YouTubeService(app(GoogleClientFactory::class), app(YouTubeMetadataBuilder::class));
        $service->assertExpectedChannel($user->refresh());

        $this->assertTrue(true, 'No exception for the expected channel.');
    }

    #[Test]
    public function a_youtube_channel_mismatch_does_not_block_a_drive_backup(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::YouTube, ['youtube_channel_id' => 'UCwrongChannel']);
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")->assertStatus(202);

        Queue::assertPushed(UploadVideoToGoogleDriveJob::class);
    }

    #[Test]
    public function publishing_another_users_project_is_refused(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $this->connect($owner, GoogleService::YouTube);
        $this->connect($owner, GoogleService::Drive);
        $project = $this->renderedProject($owner);

        Sanctum::actingAs($other = User::factory()->create());
        $this->connect($other, GoogleService::YouTube);
        $this->connect($other, GoogleService::Drive);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")->assertStatus(404);
        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")->assertStatus(404);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_user_can_hold_only_one_connection_per_service(): void
    {
        $user = User::factory()->create();
        $this->connect($user, GoogleService::YouTube);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->connect($user, GoogleService::YouTube);
    }
}
