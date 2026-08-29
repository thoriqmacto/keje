<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
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
use App\Services\Google\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Google integration.
 *
 * No test here reaches Google: the client factory and the two upload services
 * are mocked throughout.
 */
class GoogleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect_uri' => 'http://localhost:8000/api/v1/integrations/google/callback',
            'services.youtube.expected_channel_id' => 'UCGxBzjbs5jNmcdlb9WaTtVQ',
        ]);
    }

    private function connect(User $user, array $overrides = []): GoogleConnection
    {
        return GoogleConnection::create([
            'user_id' => $user->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'youtube_channel_id' => 'UCGxBzjbs5jNmcdlb9WaTtVQ',
            'youtube_channel_title' => 'Test Channel',
            'connected_at' => now(),
            ...$overrides,
        ]);
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

    // ── Connection status ───────────────────────────────────────────────────

    #[Test]
    public function the_status_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/integrations/google')->assertStatus(401);
    }

    #[Test]
    public function an_unconnected_account_reports_not_connected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.configured', true);
    }

    #[Test]
    public function tokens_are_never_exposed_through_the_api(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);

        $body = json_encode($this->getJson('/api/v1/integrations/google')->assertOk()->json());

        $this->assertStringNotContainsString('refresh-token', $body);
        $this->assertStringNotContainsString('access-token', $body);
        $this->assertStringNotContainsString('test-client-secret', $body);
    }

    #[Test]
    public function tokens_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $this->connect($user);

        $raw = \Illuminate\Support\Facades\DB::table('google_connections')
            ->where('user_id', $user->id)
            ->first();

        // The ciphertext must not contain the plaintext.
        $this->assertStringNotContainsString('refresh-token', $raw->refresh_token);
        $this->assertSame('refresh-token', $user->googleConnection->refresh_token);
    }

    #[Test]
    public function a_matching_channel_is_reported_as_verified(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.channel_matches_expected', true);
    }

    #[Test]
    public function a_different_channel_is_reported_as_a_mismatch(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, ['youtube_channel_id' => 'UCsomeoneElsesChannel']);

        $this->getJson('/api/v1/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.channel_matches_expected', false);
    }

    // ── OAuth state ─────────────────────────────────────────────────────────

    #[Test]
    public function the_callback_rejects_a_missing_or_unknown_state(): void
    {
        // Without valid state, an attacker could bind their Google account to
        // someone else's session.
        $this->get('/api/v1/integrations/google/callback?code=abc')
            ->assertRedirectContains('google=invalid');

        $this->get('/api/v1/integrations/google/callback?code=abc&state=forged')
            ->assertRedirectContains('google=invalid_state');
    }

    #[Test]
    public function the_callback_reports_a_denied_consent(): void
    {
        $this->get('/api/v1/integrations/google/callback?error=access_denied')
            ->assertRedirectContains('google=denied');
    }

    #[Test]
    public function state_is_single_use(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $url = $this->postJson('/api/v1/integrations/google/redirect')
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'];

        $oauth = app(\App\Services\Google\GoogleOAuthService::class);

        $this->assertTrue($oauth->consumeState($state)?->is($user));
        // A replayed callback must not resolve to the user again.
        $this->assertNull($oauth->consumeState($state));
    }

    #[Test]
    public function the_authorization_url_requests_offline_access(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $url = $this->postJson('/api/v1/integrations/google/redirect')
            ->assertOk()
            ->json('data.authorization_url');

        // Without offline access Google never issues a refresh token.
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('drive.file', urldecode($url));
        $this->assertStringContainsString('youtube.upload', urldecode($url));
        // Full Drive access is never requested.
        $this->assertStringNotContainsString('auth/drive ', urldecode($url).' ');
    }

    // ── Drive ───────────────────────────────────────────────────────────────

    #[Test]
    public function a_drive_backup_can_be_queued_for_a_rendered_project(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")
            ->assertStatus(202);

        Queue::assertPushed(UploadVideoToGoogleDriveJob::class);
        $this->assertSame(DriveStatus::Uploading, $project->refresh()->drive_status);
    }

    #[Test]
    public function a_drive_backup_is_refused_before_rendering(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['render']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_drive_backup_is_refused_when_google_is_not_connected(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['google']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_successful_drive_upload_records_the_file_details(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->renderedProject($user);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('upload')->once()->andReturn([
            'id' => 'drive-file-123',
            'name' => 'kajian.mp4',
            'web_view_link' => 'https://drive.google.com/file/d/drive-file-123/view',
        ]);

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive);

        $project->refresh();
        $this->assertSame(DriveStatus::Uploaded, $project->drive_status);
        $this->assertSame('drive-file-123', $project->drive_file_id);
        $this->assertNotNull($project->drive_uploaded_at);
    }

    #[Test]
    public function a_failed_drive_upload_leaves_the_render_intact(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->renderedProject($user);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('upload')->once()
            ->andThrow(new GoogleNotConnectedException('Please reconnect Google.'));

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive);

        $project->refresh();
        $this->assertSame(DriveStatus::Failed, $project->drive_status);
        // The whole point of separate pipelines.
        $this->assertSame(RenderStatus::Rendered, $project->render_status);
        $this->assertNotNull($project->output_path);
    }

    #[Test]
    public function a_drive_upload_can_be_retried_without_re_rendering(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->renderedProject($user);
        $project->forceFill([
            'drive_status' => DriveStatus::Failed,
            'drive_error' => 'previous failure',
        ])->save();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")->assertStatus(202);

        Queue::assertPushed(UploadVideoToGoogleDriveJob::class);
        $this->assertSame(RenderStatus::Rendered, $project->refresh()->render_status);
    }

    #[Test]
    public function an_already_uploaded_project_is_not_uploaded_to_drive_twice(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->renderedProject($user);
        $project->forceFill([
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'existing-id',
        ])->save();

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldNotReceive('upload');

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive);
    }

    // ── YouTube ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_youtube_upload_can_be_queued(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->renderedProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube", [
            'title' => 'Keutamaan Lapar',
            'privacy_status' => 'private',
        ])->assertStatus(202);

        Queue::assertPushed(UploadVideoToYouTubeJob::class);
        $this->assertSame(YouTubeStatus::Uploading, $project->refresh()->youtube_status);
    }

    #[Test]
    public function a_youtube_upload_is_never_triggered_by_rendering_alone(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->renderedProject($user);

        // Rendering finished, but nothing was queued for publication.
        Queue::assertNotPushed(UploadVideoToYouTubeJob::class);
        Queue::assertNotPushed(UploadVideoToGoogleDriveJob::class);
        $this->assertSame(YouTubeStatus::Pending, $project->youtube_status);
    }

    #[Test]
    public function a_duplicate_youtube_upload_is_refused(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->renderedProject($user);
        $project->forceFill([
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'abc123',
        ])->save();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_job_refuses_to_upload_a_second_copy(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->renderedProject($user);
        $project->forceFill([
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'abc123',
        ])->save();

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldNotReceive('upload');

        (new UploadVideoToYouTubeJob($project->id))->handle($youtube);
    }

    #[Test]
    public function a_successful_upload_records_the_video_id_and_url(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->renderedProject($user);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('upload')->once()->andReturn([
            'id' => 'dQw4w9WgXcQ',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'privacy_status' => 'private',
            'publish_at' => null,
        ]);

        (new UploadVideoToYouTubeJob($project->id))->handle($youtube);

        $project->refresh();
        $this->assertSame(YouTubeStatus::Uploaded, $project->youtube_status);
        $this->assertSame('dQw4w9WgXcQ', $project->youtube_video_id);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $project->youtube_url);
    }

    #[Test]
    public function a_scheduled_upload_is_recorded_as_scheduled(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->renderedProject($user);
        $publishAt = now()->addWeek();

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('upload')->once()->andReturn([
            'id' => 'vid123',
            'url' => 'https://www.youtube.com/watch?v=vid123',
            'privacy_status' => 'private',
            'publish_at' => $publishAt->toIso8601String(),
        ]);

        (new UploadVideoToYouTubeJob($project->id))->handle($youtube);

        $project->refresh();
        $this->assertSame(YouTubeStatus::Scheduled, $project->youtube_status);
        $this->assertNotNull($project->youtube_publish_at);
    }

    #[Test]
    public function a_publish_time_in_the_past_is_rejected(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
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
        $this->connect($user, ['youtube_channel_id' => 'UCwrongChannel']);

        $service = new YouTubeService(app(GoogleClientFactory::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not the expected channel');

        $service->assertExpectedChannel($user->refresh());
    }

    #[Test]
    public function an_upload_to_the_expected_channel_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->connect($user);

        $service = new YouTubeService(app(GoogleClientFactory::class));
        $service->assertExpectedChannel($user->refresh());

        $this->assertTrue(true, 'No exception for the expected channel.');
    }

    #[Test]
    public function publishing_another_users_project_is_refused(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $this->connect($owner);
        $project = $this->renderedProject($owner);

        Sanctum::actingAs($other = User::factory()->create());
        $this->connect($other);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/drive")->assertStatus(404);
        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")->assertStatus(404);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function disconnecting_removes_the_stored_credentials(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);

        $this->deleteJson('/api/v1/integrations/google')->assertOk();

        $this->assertDatabaseMissing('google_connections', ['user_id' => $user->id]);
    }
}
