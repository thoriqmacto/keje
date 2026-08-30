<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\GoogleService;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Jobs\UploadVideoToYouTubeJob;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\GoogleErrorTranslator;
use App\Services\Google\YouTubePlaylistAssigner;
use App\Services\Google\YouTubeService;
use App\Services\Media\MediaRetention;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Playlist membership after a YouTube upload.
 *
 * The invariant that matters most: retrying a failed assignment must never
 * reach videos.insert. A duplicate lecture on the channel is worse than no
 * playlist at all, and unlike a playlist error it cannot be undone from here.
 */
class YouTubePlaylistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.google.clients.youtube.client_id' => 'youtube-client-id',
            'services.google.clients.youtube.client_secret' => 'youtube-secret',
            'services.google.clients.youtube.redirect_uri' => 'http://localhost:8000/api/v1/integrations/youtube/callback',
            // Off so the MP4 survives for assertions about the upload itself.
            'media.retention.prune_output_after_backup' => false,
            'media.retention.prune_sources_after_backup' => false,
        ]);
    }

    /** @param  list<string>|null  $scopes */
    private function connect(User $user, ?array $scopes = null): GoogleConnection
    {
        return GoogleConnection::create([
            'user_id' => $user->id,
            'service' => GoogleService::YouTube,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => $scopes ?? GoogleService::YouTube->scopes(),
            'connected_at' => now(),
        ]);
    }

    private function uploadedProject(User $user, array $overrides = []): ContentProject
    {
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id, ...$overrides]);

        $path = "content/{$project->uuid}/renders/output.mp4";
        Storage::disk('local')->put($path, 'mp4');

        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $path,
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
        ])->save();

        return $project->refresh();
    }

    private function googleError(string $reason, int $code = 400): GoogleServiceException
    {
        return new GoogleServiceException('Google error', $code, null, [
            ['reason' => $reason, 'message' => $reason],
        ]);
    }

    private function assigner(YouTubeService $youtube): YouTubePlaylistAssigner
    {
        return new YouTubePlaylistAssigner($youtube, app(GoogleErrorTranslator::class));
    }

    // ── Assignment during upload ────────────────────────────────────────────

    #[Test]
    public function a_successful_assignment_records_the_playlist_item(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $topic = ContentTopic::factory()->create(['user_id' => $user->id, 'youtube_playlist_id' => 'PLtopic']);
        $project = $this->uploadedProject($user, ['topic_id' => $topic->id]);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('addToPlaylist')->once()
            ->with(Mockery::type(User::class), 'PLtopic', 'dQw4w9WgXcQ')
            ->andReturn('PLI_item_1');

        $this->assertTrue($this->assigner($youtube)->assign($project->fresh(['topic'])));

        $project->refresh();
        $this->assertSame('PLI_item_1', $project->youtube_playlist_item_id);
        $this->assertSame('PLtopic', $project->youtube_playlist_id);
        $this->assertNotNull($project->youtube_playlist_added_at);
        $this->assertNull($project->youtube_playlist_error);
    }

    #[Test]
    public function nothing_is_attempted_when_no_playlist_resolves(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $topic = ContentTopic::factory()->create(['user_id' => $user->id, 'youtube_playlist_id' => null]);
        $project = $this->uploadedProject($user, ['topic_id' => $topic->id, 'youtube_metadata' => []]);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldNotReceive('addToPlaylist');

        $this->assertFalse($this->assigner($youtube)->assign($project->fresh(['topic'])));
    }

    #[Test]
    public function a_failed_assignment_leaves_the_video_uploaded(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->uploadedProject($user, ['youtube_metadata' => ['playlist_id' => 'PLgone']]);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('addToPlaylist')->once()
            ->andThrow($this->googleError('playlistNotFound', 404));

        $this->assertFalse($this->assigner($youtube)->assign($project));

        $project->refresh();
        // The whole point: the upload is not undone by a playlist problem.
        $this->assertSame(YouTubeStatus::Uploaded, $project->youtube_status);
        $this->assertSame('dQw4w9WgXcQ', $project->youtube_video_id);
        // But it is no longer invisible.
        $this->assertSame('That playlist no longer exists on the connected channel.', $project->youtube_playlist_error);
        $this->assertNull($project->youtube_playlist_item_id);
    }

    #[Test]
    public function a_video_already_in_the_playlist_is_treated_as_success(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->uploadedProject($user, ['youtube_metadata' => ['playlist_id' => 'PLriyadh']]);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('addToPlaylist')->once()
            ->andThrow($this->googleError('videoAlreadyInPlaylist', 409));

        // The desired end state, reached earlier. Not an error to report.
        $this->assertTrue($this->assigner($youtube)->assign($project));

        $project->refresh();
        $this->assertNull($project->youtube_playlist_error);
        $this->assertSame('PLriyadh', $project->youtube_playlist_id);
    }

    #[Test]
    public function an_already_assigned_project_does_not_spend_quota_again(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $project = $this->uploadedProject($user, ['youtube_metadata' => ['playlist_id' => 'PLriyadh']]);
        $project->forceFill([
            'youtube_playlist_id' => 'PLriyadh',
            'youtube_playlist_item_id' => 'PLI_existing',
        ])->save();

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldNotReceive('addToPlaylist');

        $this->assertTrue($this->assigner($youtube)->assign($project->refresh()));
    }

    #[Test]
    public function the_upload_job_assigns_the_playlist_without_failing_on_it(): void
    {
        $user = User::factory()->create();
        $this->connect($user);
        $topic = ContentTopic::factory()->create(['user_id' => $user->id, 'youtube_playlist_id' => 'PLtopic']);

        $project = ContentProject::factory()->withMedia()->create([
            'user_id' => $user->id,
            'topic_id' => $topic->id,
        ]);
        $path = "content/{$project->uuid}/renders/output.mp4";
        Storage::disk('local')->put($path, 'mp4');
        $project->forceFill(['render_status' => RenderStatus::Rendered, 'output_path' => $path])->save();

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldReceive('upload')->once()->andReturn([
            'id' => 'vid1',
            'url' => 'https://www.youtube.com/watch?v=vid1',
            'privacy_status' => 'private',
            'publish_at' => null,
        ]);

        $assigner = Mockery::mock(YouTubePlaylistAssigner::class);
        $assigner->shouldReceive('assign')->once()->andReturn(false);

        (new UploadVideoToYouTubeJob($project->id))
            ->handle($youtube, app(MediaRetention::class), $assigner);

        // Playlist failure does not roll back the upload.
        $this->assertSame(YouTubeStatus::Uploaded, $project->refresh()->youtube_status);
        $this->assertSame('vid1', $project->youtube_video_id);
    }

    // ── The retry endpoint ──────────────────────────────────────────────────

    #[Test]
    public function retrying_a_playlist_never_uploads_the_video_again(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploadedProject($user, ['youtube_metadata' => ['playlist_id' => 'PLriyadh']]);
        $project->forceFill(['youtube_playlist_error' => 'previous failure'])->save();

        $youtube = Mockery::mock(YouTubeService::class);
        // The invariant. A retry that re-uploads publishes a duplicate lecture.
        $youtube->shouldNotReceive('upload');
        $youtube->shouldReceive('addToPlaylist')->once()->andReturn('PLI_retry');
        $this->app->instance(YouTubeService::class, $youtube);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/playlist")
            ->assertOk()
            ->assertJsonPath('data.youtube_playlist.item_id', 'PLI_retry');

        $project->refresh();
        $this->assertSame('dQw4w9WgXcQ', $project->youtube_video_id);
        $this->assertNull($project->youtube_playlist_error);
    }

    #[Test]
    public function a_retry_is_refused_before_the_video_exists(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/playlist")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['youtube']);
    }

    #[Test]
    public function a_retry_is_refused_when_the_grant_cannot_manage_playlists(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        // The pre-upgrade grant.
        $this->connect($user, [
            GoogleService::SCOPE_YOUTUBE_UPLOAD,
            GoogleService::SCOPE_YOUTUBE_READONLY,
        ]);
        $project = $this->uploadedProject($user, ['youtube_metadata' => ['playlist_id' => 'PLriyadh']]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/playlist")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['youtube']);
    }

    #[Test]
    public function a_retry_is_refused_when_no_playlist_is_chosen(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $topic = ContentTopic::factory()->create(['user_id' => $user->id, 'youtube_playlist_id' => null]);
        $project = $this->uploadedProject($user, ['topic_id' => $topic->id, 'youtube_metadata' => []]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/playlist")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['playlist']);
    }

    #[Test]
    public function another_users_project_cannot_be_assigned(): void
    {
        $owner = User::factory()->create();
        $this->connect($owner);
        $project = $this->uploadedProject($owner, ['youtube_metadata' => ['playlist_id' => 'PLriyadh']]);

        Sanctum::actingAs($other = User::factory()->create());
        $this->connect($other);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/playlist")->assertStatus(404);
    }

    #[Test]
    public function a_failed_retry_reports_why_without_losing_the_video(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->uploadedProject($user, ['youtube_metadata' => ['playlist_id' => 'PLgone']]);

        $youtube = Mockery::mock(YouTubeService::class);
        $youtube->shouldNotReceive('upload');
        $youtube->shouldReceive('addToPlaylist')->once()
            ->andThrow($this->googleError('playlistItemsNotAccessible', 403));
        $this->app->instance(YouTubeService::class, $youtube);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/playlist")
            ->assertStatus(422);

        $project->refresh();
        $this->assertSame('dQw4w9WgXcQ', $project->youtube_video_id);
        $this->assertSame('Keje cannot add videos to that playlist.', $project->youtube_playlist_error);
    }

    // ── Error translation ───────────────────────────────────────────────────

    #[Test]
    public function google_errors_become_sentences_a_person_can_act_on(): void
    {
        $translator = app(GoogleErrorTranslator::class);

        $this->assertStringContainsString('quota', $translator->translate($this->googleError('quotaExceeded', 403)));
        $this->assertStringContainsString('no longer exists', $translator->translate($this->googleError('playlistNotFound', 404)));
        $this->assertStringContainsString(
            'Reconnect',
            $translator->translate(new GoogleServiceException('Unauthorized', 401)),
        );
        // Anything unrecognised falls back rather than dumping the exception.
        $this->assertSame('fallback', $translator->translate(new RuntimeException('boom'), 'fallback'));
    }

    #[Test]
    public function a_quota_error_is_not_mistaken_for_an_expired_grant(): void
    {
        $translator = app(GoogleErrorTranslator::class);

        // Reconnecting would not help, and prompting for it would be wrong.
        $this->assertFalse($translator->isExpiredGrant($this->googleError('quotaExceeded', 403)));
        $this->assertTrue($translator->isExpiredGrant(new GoogleServiceException('nope', 401)));
    }
}
