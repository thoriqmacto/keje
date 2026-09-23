<?php

namespace Tests\Feature\Studio;

use App\Enums\GoogleService;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Notifications\GoogleConnectionNeedsAttention;
use App\Services\Google\GoogleApiReachability;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleConnectionHealth;
use App\Services\Google\GoogleGrantClock;
use App\Services\Google\GoogleNotConnectedException;
use App\Services\Google\GoogleOAuthClassifier;
use App\Services\Google\GoogleOAuthService;
use App\Services\Google\GoogleServiceLabels;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Knowing a Google connection is dead before an upload finds out.
 *
 * The complaint this answers: a connection row stays present long after
 * Google stops honouring the token in it, so the first anyone heard of a dead
 * grant was a failed upload — after a render had been spent on it.
 *
 * Two things are being tested, and they are different in kind. A **probe** is
 * the only way to learn a published app's grant has been revoked, because
 * Google publishes no expiry for one. A **clock** exists in exactly one case,
 * the seven days a Testing-mode consent screen gives its refresh tokens, and
 * that is the only honest "warn me in advance".
 */
class GoogleConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        config()->set('services.google.clients.youtube', [
            'client_id' => 'yt-id', 'client_secret' => 'yt-secret',
            'redirect_uri' => 'https://keje.test/callback/youtube',
        ]);
        config()->set('services.google.clients.drive', [
            'client_id' => 'dr-id', 'client_secret' => 'dr-secret',
            'redirect_uri' => 'https://keje.test/callback/drive',
        ]);
    }

    private function connection(GoogleService $service = GoogleService::YouTube, array $attributes = []): GoogleConnection
    {
        return GoogleConnection::query()->create([
            'user_id' => $this->user->id,
            'service' => $service,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => $service->scopes(),
            'connected_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * Replace the client factory so nothing in these tests reaches Google.
     *
     * `$throws` is what forUser() does when the refresh fails, which is the
     * shape the health service actually has to classify.
     */
    private function fakeClients(?Throwable $throws = null): void
    {
        $mock = Mockery::mock(GoogleClientFactory::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);

        if ($throws !== null) {
            $mock->shouldReceive('forUser')->andThrow($throws);
        } else {
            $mock->shouldReceive('forUser')->andReturn(new \Google\Client);
        }

        $this->instance(GoogleClientFactory::class, $mock);

        // The one part that talks to Google. Faked for the same reason
        // ffprobe is throughout the media tests: what is interesting here is
        // how a result is interpreted, and that should not need the internet.
        $reachable = Mockery::mock(GoogleApiReachability::class);
        $reachable->shouldReceive('touch')->andReturnNull();
        $this->instance(GoogleApiReachability::class, $reachable);
    }

    /** A connection that refreshes fine but cannot reach its API. */
    private function fakeUnreachableApi(Throwable $throws): void
    {
        $this->fakeClients();

        $reachable = Mockery::mock(GoogleApiReachability::class);
        $reachable->shouldReceive('touch')->andThrow($throws);
        $this->instance(GoogleApiReachability::class, $reachable);
    }

    /**
     * Answer the authorization-code exchange with a canned token, leaving
     * every line of our own OAuth code running for real.
     */
    private function fakeTokenExchange(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'access_token' => 'fresh-access',
                'refresh_token' => 'fresh-refresh',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => implode(' ', GoogleService::YouTube->scopes()),
            ])),
        ]));

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

    // ── The classifier ──────────────────────────────────────────────────────

    #[Test]
    public function a_dead_grant_and_a_rejected_client_get_different_advice(): void
    {
        /*
         * The distinction the old code could not make. Every failure said
         * "reconnect", which fixes a revoked token and does nothing at all
         * for a mistyped client secret — so the advice sent somebody to press
         * a button that could never have helped.
         */
        $classifier = new GoogleOAuthClassifier;

        $grant = $classifier->classify('{"error":"invalid_grant","error_description":"Token has been expired or revoked."}');
        $client = $classifier->classify('{"error":"invalid_client","error_description":"Unauthorized"}');

        $this->assertSame(GoogleOAuthClassifier::RENEW_REQUIRED, $grant['status']);
        $this->assertSame(GoogleOAuthClassifier::INVALID_CLIENT, $client['status']);

        $this->assertStringContainsString('Reconnect', implode(' ', $grant['guidance']));
        $this->assertStringNotContainsString('Reconnect', implode(' ', $client['guidance']));
    }

    #[Test]
    public function a_network_fault_never_blames_the_credentials(): void
    {
        // And never alerts: waking somebody for a blip is how they learn to
        // ignore the alerts that matter.
        $verdict = (new GoogleOAuthClassifier)->classify('cURL error 6: Could not resolve host: oauth2.googleapis.com');

        $this->assertSame(GoogleOAuthClassifier::UNREACHABLE, $verdict['status']);
        $this->assertNotContains(GoogleOAuthClassifier::UNREACHABLE, GoogleOAuthClassifier::alertableStatuses());
        $this->assertNotContains(GoogleOAuthClassifier::UNREACHABLE, GoogleOAuthClassifier::brokenStatuses());
    }

    #[Test]
    public function a_disabled_api_is_not_reported_as_a_token_problem(): void
    {
        $verdict = (new GoogleOAuthClassifier)->classify(
            'YouTube Data API v3 has not been used in project 123 before or it is disabled',
        );

        $this->assertSame(GoogleOAuthClassifier::API_DISABLED, $verdict['status']);
        $this->assertStringContainsString('Cloud console', implode(' ', $verdict['guidance']));
    }

    #[Test]
    public function an_invalid_grant_wins_over_a_permission_word_in_the_same_message(): void
    {
        // Google's rejection of a dead refresh token can carry both, and
        // blaming the scope would send somebody to re-grant a permission they
        // already have.
        $verdict = (new GoogleOAuthClassifier)->classify('403 Forbidden invalid_grant');

        $this->assertSame(GoogleOAuthClassifier::RENEW_REQUIRED, $verdict['status']);
    }

    #[Test]
    public function guidance_never_echoes_what_google_said(): void
    {
        /*
         * A failed token exchange describes a request carrying the client
         * secret and the refresh token, and Google's error bodies quote
         * request parameters back. Returning them to a browser would publish
         * the credential the failure was about.
         */
        $secret = 'GOCSPX-super-secret-value';
        $verdict = (new GoogleOAuthClassifier)->classify(
            "invalid_client client_secret={$secret} refresh_token=1//0ggggg",
        );

        $rendered = $verdict['message'].' '.implode(' ', $verdict['guidance']);

        $this->assertStringNotContainsString($secret, $rendered);
        $this->assertStringNotContainsString('1//0ggggg', $rendered);
    }

    // ── The clock ───────────────────────────────────────────────────────────

    #[Test]
    public function a_published_consent_screen_has_no_countdown_to_offer(): void
    {
        /*
         * The honest half. Google publishes no expiry for a refresh token from
         * a published app — it lives until revoked — so any countdown here
         * would be invented. Null means unknowable, never "plenty of time".
         */
        config()->set('services.google.health.consent_screen_testing', false);

        $connection = $this->connection(attributes: ['connected_at' => now()->subDays(30)]);
        $clock = new GoogleGrantClock;

        $this->assertNull($clock->expiresAt($connection));
        $this->assertNull($clock->secondsRemaining($connection));
        $this->assertFalse($clock->isExpiringSoon($connection));
    }

    #[Test]
    public function a_testing_mode_grant_expires_seven_days_after_it_was_made(): void
    {
        config()->set('services.google.health.consent_screen_testing', true);

        $granted = Carbon::parse('2026-09-01T09:00:00Z');
        $connection = $this->connection(attributes: ['connected_at' => $granted]);

        $this->assertTrue(
            (new GoogleGrantClock)->expiresAt($connection)->equalTo($granted->copy()->addDays(7)),
        );
    }

    #[Test]
    public function it_warns_inside_the_window_and_not_before(): void
    {
        config()->set('services.google.health.consent_screen_testing', true);
        config()->set('services.google.health.warn_within_hours', 48);

        $clock = new GoogleGrantClock;
        $connection = $this->connection(attributes: ['connected_at' => now()->subDays(3)]);

        // Four days left of seven: nothing to say yet.
        $this->assertFalse($clock->isExpiringSoon($connection));

        $connection->forceFill(['connected_at' => now()->subDays(6)])->save();
        $this->assertTrue($clock->isExpiringSoon($connection->fresh()));
    }

    #[Test]
    public function a_grant_that_has_already_lapsed_is_not_called_expiring_soon(): void
    {
        // The probe will have reported it renew_required, which is stronger
        // and truer than a countdown reading zero.
        config()->set('services.google.health.consent_screen_testing', true);

        $connection = $this->connection(attributes: ['connected_at' => now()->subDays(10)]);

        $this->assertFalse((new GoogleGrantClock)->isExpiringSoon($connection));
    }

    // ── The probe ───────────────────────────────────────────────────────────

    #[Test]
    public function a_rejected_refresh_token_is_recorded_as_needing_renewal(): void
    {
        $this->fakeClients(new GoogleNotConnectedException(
            'The YouTube connection is no longer valid. Please reconnect it.',
            'invalid_grant',
        ));

        $connection = $this->connection();
        $result = app(GoogleConnectionHealth::class)->check($this->user, GoogleService::YouTube);

        $this->assertSame(GoogleOAuthClassifier::RENEW_REQUIRED, $result['status']);
        $this->assertSame(GoogleOAuthClassifier::RENEW_REQUIRED, $connection->fresh()->health_status);
        $this->assertNotNull($connection->fresh()->health_failing_since);
    }

    #[Test]
    public function the_oauth_code_is_what_makes_the_verdict_specific(): void
    {
        /*
         * GoogleNotConnectedException carries the same friendly sentence
         * whatever went wrong, so without the code every exchange failure
         * would classify as unknown_error — and telling those apart is the
         * entire job of this service.
         */
        $this->fakeClients(new GoogleNotConnectedException(
            'The YouTube connection is no longer valid. Please reconnect it.',
            'invalid_client',
        ));

        $this->connection();

        $this->assertSame(
            GoogleOAuthClassifier::INVALID_CLIENT,
            app(GoogleConnectionHealth::class)->check($this->user, GoogleService::YouTube)['status'],
        );
    }

    #[Test]
    public function a_token_that_refreshes_but_cannot_reach_its_api_is_caught(): void
    {
        /*
         * The gap a token exchange alone leaves. A Cloud project with the API
         * left disabled hands out perfectly valid tokens that fail on first
         * use — so a check that stopped at "the grant is alive" would report
         * this connection healthy right up until an upload proved otherwise.
         */
        $this->fakeUnreachableApi(new \RuntimeException(
            'YouTube Data API v3 has not been used in project 42 before or it is disabled',
        ));
        $this->connection();

        $result = app(GoogleConnectionHealth::class)->check($this->user, GoogleService::YouTube);

        $this->assertSame(GoogleOAuthClassifier::API_DISABLED, $result['status']);
        $this->assertContains($result['status'], GoogleOAuthClassifier::brokenStatuses());
    }

    #[Test]
    public function a_connection_that_has_never_been_made_is_disconnected_not_broken(): void
    {
        $result = app(GoogleConnectionHealth::class)->check($this->user, GoogleService::Drive);

        $this->assertSame(GoogleOAuthClassifier::DISCONNECTED, $result['status']);
        $this->assertNotContains($result['status'], GoogleOAuthClassifier::brokenStatuses());
    }

    #[Test]
    public function a_missing_configuration_is_not_reported_as_a_dead_token(): void
    {
        config()->set('services.google.clients.drive', [
            'client_id' => null, 'client_secret' => null, 'redirect_uri' => null,
        ]);

        $result = app(GoogleConnectionHealth::class)->check($this->user, GoogleService::Drive);

        $this->assertSame(GoogleOAuthClassifier::NOT_CONFIGURED, $result['status']);
    }

    #[Test]
    public function recovering_clears_the_record_of_when_it_broke(): void
    {
        $connection = $this->connection(attributes: [
            'health_status' => GoogleOAuthClassifier::RENEW_REQUIRED,
            'health_failing_since' => now()->subDays(3),
        ]);

        $this->fakeClients();
        app(GoogleConnectionHealth::class)->check($this->user, GoogleService::YouTube);

        $this->assertNull($connection->fresh()->health_failing_since);
    }

    #[Test]
    public function reconnecting_clears_a_stale_broken_verdict(): void
    {
        /*
         * Without this the banner would stay up and the upload preflight
         * would keep refusing after somebody had already fixed the problem,
         * until an hourly check happened to disagree.
         *
         * This runs the real completeConnection() with Google's transport
         * faked, rather than writing the columns by hand and reading them
         * back — the thing worth proving is that the OAuth callback clears
         * them, and only the real code can prove that.
         */
        $this->connection(attributes: [
            'health_status' => GoogleOAuthClassifier::RENEW_REQUIRED,
            'health_message' => 'Google rejected the stored credentials.',
            'health_guidance' => ['Reconnect from Settings → Integrations.'],
            'health_checked_at' => now()->subHour(),
            'health_failing_since' => now()->subDay(),
            'health_alerted_at' => now()->subDay(),
            'health_alerted_status' => GoogleOAuthClassifier::RENEW_REQUIRED,
        ]);

        $this->fakeTokenExchange();

        app(GoogleOAuthService::class)->completeConnection(
            $this->user,
            GoogleService::YouTube,
            'fresh-authorization-code',
        );

        $connection = $this->user->googleConnectionFor(GoogleService::YouTube);

        $this->assertNull($connection->health_status);
        $this->assertNull($connection->health_message);
        $this->assertNull($connection->health_guidance);
        $this->assertNull($connection->health_checked_at);
        $this->assertNull($connection->health_failing_since);
        $this->assertNull($connection->health_alerted_at);
        $this->assertNull($connection->health_alerted_status);

        // A reconnect also restarts the seven-day clock a Testing-mode
        // consent screen puts on the grant it just issued.
        $this->assertTrue($connection->connected_at->isAfter(now()->subMinute()));
    }

    // ── The alert ───────────────────────────────────────────────────────────

    #[Test]
    public function the_scheduled_check_alerts_once_rather_than_every_hour(): void
    {
        Notification::fake();

        $this->fakeClients(new GoogleNotConnectedException('gone', 'invalid_grant'));
        $this->connection();

        $this->artisan('google:health')->assertExitCode(1);
        Notification::assertSentTimes(GoogleConnectionNeedsAttention::class, 1);

        // Still broken an hour later, and still the same problem: nothing new
        // to say, so nothing is said.
        $this->artisan('google:health')->assertExitCode(1);
        Notification::assertSentTimes(GoogleConnectionNeedsAttention::class, 1);
    }

    #[Test]
    public function a_problem_that_gets_worse_speaks_up_again(): void
    {
        /*
         * The transition worth a second interruption: a connection that was
         * warning about a seven-day expiry has now actually died. Staying
         * quiet because *something* was already sent would hide exactly the
         * change that matters.
         */
        Notification::fake();

        $connection = $this->connection(attributes: [
            'health_alerted_at' => now()->subMinutes(5),
            'health_alerted_status' => GoogleOAuthClassifier::EXPIRING_SOON,
        ]);

        $this->fakeClients(new GoogleNotConnectedException('gone', 'invalid_grant'));

        $this->artisan('google:health');

        Notification::assertSentTimes(GoogleConnectionNeedsAttention::class, 1);
        $this->assertSame(GoogleOAuthClassifier::RENEW_REQUIRED, $connection->fresh()->health_alerted_status);
    }

    #[Test]
    public function a_network_blip_is_recorded_but_never_alerted(): void
    {
        Notification::fake();

        $this->fakeClients(new \RuntimeException('cURL error 28: Operation timed out'));
        $this->connection();

        $this->artisan('google:health')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_healthy_run_says_nothing_and_exits_clean(): void
    {
        Notification::fake();

        $this->fakeClients();
        $this->connection();

        $this->artisan('google:health')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertSame(GoogleOAuthClassifier::HEALTHY, GoogleConnection::first()->health_status);
    }

    // ── Preflight ───────────────────────────────────────────────────────────

    #[Test]
    public function an_upload_is_refused_at_the_button_rather_than_after_the_render(): void
    {
        /*
         * The whole point. A dead grant used to queue a job, spend an upload
         * slot and report itself only once the job ran; now the button says
         * so, and says what to do about it.
         */
        $this->fakeClients(new GoogleNotConnectedException('gone', 'invalid_grant'));
        $this->connection();

        $project = ContentProject::factory()->for($this->user)->create([
            'output_path' => 'content/x/output/video.mp4',
        ]);

        $response = $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")
            ->assertStatus(422)
            ->assertJsonValidationErrors('google');

        // The guidance travels with the refusal, so the studio can show what
        // to do rather than a sentence saying something went wrong.
        $this->assertStringContainsString(
            'Reconnect',
            implode(' ', $response->json('errors.google')),
        );

        $this->assertNull($project->fresh()->youtube_video_id);
    }

    #[Test]
    public function an_unreachable_google_does_not_block_an_upload(): void
    {
        // A blip between this server and Google says nothing about the
        // credentials, and the queued job tries again from its own process.
        $this->fakeClients(new \RuntimeException('cURL error 28: Operation timed out'));
        $this->connection();

        $project = ContentProject::factory()->for($this->user)->create([
            'output_path' => 'content/x/output/video.mp4',
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")->assertStatus(202);
    }

    // ── The endpoint ────────────────────────────────────────────────────────

    #[Test]
    public function the_health_endpoint_reads_rather_than_probes(): void
    {
        // A banner asks this on every page load; probing there would put two
        // Google round trips in front of every navigation.
        $this->connection(attributes: [
            'health_status' => GoogleOAuthClassifier::RENEW_REQUIRED,
            'health_message' => 'Google rejected the stored credentials.',
            'health_guidance' => ['Reconnect from Settings → Integrations.'],
            'health_checked_at' => now()->subMinutes(20),
        ]);

        $this->getJson('/api/v1/integrations/google/health')
            ->assertOk()
            ->assertJsonPath('data.youtube.status', GoogleOAuthClassifier::RENEW_REQUIRED)
            ->assertJsonPath('data.drive.status', GoogleOAuthClassifier::DISCONNECTED)
            ->assertJsonPath('data.youtube.guidance.0', 'Reconnect from Settings → Integrations.');
    }

    #[Test]
    public function a_connection_nobody_has_checked_says_so(): void
    {
        // Rather than claiming a health nobody established.
        $this->connection();

        $this->getJson('/api/v1/integrations/google/health')
            ->assertOk()
            ->assertJsonPath('data.youtube.message', 'This connection has not been checked yet.');
    }

    #[Test]
    public function the_health_endpoint_never_returns_a_token(): void
    {
        $this->connection(attributes: [
            'health_status' => GoogleOAuthClassifier::HEALTHY,
            'health_message' => 'Connected and working.',
            'health_checked_at' => now(),
        ]);

        $body = $this->getJson('/api/v1/integrations/google/health')->assertOk()->getContent();

        $this->assertStringNotContainsString('refresh', $body);
        $this->assertStringNotContainsString('access_token', $body);
    }

    #[Test]
    public function health_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/integrations/google/health')->assertUnauthorized();
    }

    #[Test]
    public function the_labels_helper_carries_what_a_message_needs(): void
    {
        $labels = GoogleServiceLabels::for(GoogleService::YouTube);

        $this->assertSame(GoogleService::YouTube->label(), $labels->label);
        $this->assertSame(GoogleService::YouTube->envPrefix(), $labels->envPrefix);
    }
}
