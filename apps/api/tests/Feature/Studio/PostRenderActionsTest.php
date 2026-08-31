<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\GoogleService;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Jobs\RenderContentProjectJob;
use App\Jobs\UploadVideoToGoogleDriveJob;
use App\Jobs\UploadVideoToYouTubeJob;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Media\MediaRetention;
use App\Services\Media\RenderInputFingerprint;
use App\Services\Media\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Back up to Drive" and "Upload to YouTube", chosen when the render is
 * requested and honoured when it succeeds.
 *
 * The three pipelines stay independent: a Google failure must never turn a
 * good render into a failed one, and the local MP4 must survive until every
 * consumer that was asked for has actually had it.
 */
class PostRenderActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function connect(User $user, GoogleService ...$services): void
    {
        foreach ($services as $service) {
            GoogleConnection::create([
                'user_id' => $user->id,
                'service' => $service,
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'token_expires_at' => now()->addHour(),
                'scopes' => $service->scopes(),
                'connected_at' => now(),
            ]);
        }
    }

    private function renderable(User $user): ContentProject
    {
        return ContentProject::factory()->withMediaFiles()->create([
            'user_id' => $user->id,
            'primary_title' => 'Keutamaan Lapar',
        ]);
    }

    /** Drive the job to success with a faked encode. */
    private function succeed(ContentProject $project, array $postActions): void
    {
        $job = $project->renderJobs()->create([
            'status' => RenderJobStatus::Queued,
            'progress_percent' => 0,
            'post_actions' => $postActions,
        ]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldReceive('render')->andReturn([
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'size' => 1024,
            'duration' => 60.0,
            'exit_code' => 0,
            'log' => '',
        ]);

        (new RenderContentProjectJob($project->id, $job->id))
            ->handle($renderer, app(RenderInputFingerprint::class));
    }

    // ── Dispatch ────────────────────────────────────────────────────────────

    #[Test]
    public function neither_action_queues_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connect($user, GoogleService::Drive, GoogleService::YouTube);

        $this->succeed($this->renderable($user), ['drive_backup' => false, 'youtube_upload' => false]);

        Queue::assertNotPushed(UploadVideoToGoogleDriveJob::class);
        Queue::assertNotPushed(UploadVideoToYouTubeJob::class);
    }

    #[Test]
    public function drive_only_queues_only_drive(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->succeed($this->renderable($user), ['drive_backup' => true, 'youtube_upload' => false]);

        Queue::assertPushed(UploadVideoToGoogleDriveJob::class);
        Queue::assertNotPushed(UploadVideoToYouTubeJob::class);
    }

    #[Test]
    public function youtube_only_queues_only_youtube(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->succeed($this->renderable($user), ['drive_backup' => false, 'youtube_upload' => true]);

        Queue::assertPushed(UploadVideoToYouTubeJob::class);
        Queue::assertNotPushed(UploadVideoToGoogleDriveJob::class);
    }

    #[Test]
    public function both_actions_queue_both_jobs(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->succeed($this->renderable($user), ['drive_backup' => true, 'youtube_upload' => true]);

        Queue::assertPushed(UploadVideoToGoogleDriveJob::class);
        Queue::assertPushed(UploadVideoToYouTubeJob::class);
    }

    #[Test]
    public function a_failed_render_queues_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->renderable($user);

        $job = $project->renderJobs()->create([
            'status' => RenderJobStatus::Queued,
            'progress_percent' => 0,
            'post_actions' => ['drive_backup' => true, 'youtube_upload' => true],
        ]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldReceive('render')->andThrow(
            new \App\Exceptions\Media\RenderFailedException('FFmpeg gave up.'),
        );

        (new RenderContentProjectJob($project->id, $job->id))
            ->handle($renderer, app(RenderInputFingerprint::class));

        $this->assertSame(RenderStatus::Failed, $project->refresh()->render_status);
        Queue::assertNotPushed(UploadVideoToGoogleDriveJob::class);
        Queue::assertNotPushed(UploadVideoToYouTubeJob::class);
    }

    #[Test]
    public function a_project_that_already_has_a_video_is_never_uploaded_again(): void
    {
        // Re-rendering must not publish a second copy: the video exists, and
        // videos.insert has no idea it is a repeat.
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->renderable($user);
        $project->forceFill([
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Uploaded,
        ])->save();

        $this->succeed($project, ['drive_backup' => false, 'youtube_upload' => true]);

        Queue::assertNotPushed(UploadVideoToYouTubeJob::class);
    }

    // ── Requested vs possible ───────────────────────────────────────────────

    #[Test]
    public function asking_for_a_disconnected_destination_is_dropped_at_queue_time(): void
    {
        // Queueing a job that can only fail wastes a worker and reports the
        // failure twenty minutes later; refuse it while the person is here.
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive);
        $project = $this->renderable($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render", [
            'post_actions' => ['drive_backup' => true, 'youtube_upload' => true],
        ])->assertStatus(202);

        $this->assertSame(
            ['drive_backup' => true, 'youtube_upload' => false],
            $project->latestRenderJob()->post_actions,
        );
    }

    #[Test]
    public function the_choice_is_snapshotted_on_the_attempt(): void
    {
        // Stored with the attempt, not the project: the job may sit on the
        // queue, and editing the project afterwards must not change what
        // happens when it finishes.
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, GoogleService::Drive, GoogleService::YouTube);
        $project = $this->renderable($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render", [
            'post_actions' => ['drive_backup' => true, 'youtube_upload' => true],
        ])->assertStatus(202);

        $this->assertSame(
            ['drive_backup' => true, 'youtube_upload' => true],
            $project->latestRenderJob()->post_actions,
        );
    }

    // ── Pruning race ────────────────────────────────────────────────────────

    #[Test]
    public function drive_finishing_first_keeps_the_output_for_youtube(): void
    {
        $user = User::factory()->create();
        $project = $this->renderable($user);
        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            // YouTube has not run yet.
            'youtube_status' => YouTubeStatus::Pending,
        ])->save();

        Storage::disk('local')->put($project->output_path, 'mp4');

        $freed = app(MediaRetention::class)->prune($project->refresh());

        // Sources go, the MP4 stays: something else still needs it.
        $this->assertTrue($freed['sources']);
        $this->assertFalse($freed['output']);
        Storage::disk('local')->assertExists($project->output_path);
    }

    #[Test]
    public function youtube_finishing_first_prunes_nothing_without_a_drive_copy(): void
    {
        $user = User::factory()->create();
        $project = $this->renderable($user);
        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'abc123',
            // Drive has not confirmed, so there is no backup to fall back on.
            'drive_status' => DriveStatus::Pending,
        ])->save();

        Storage::disk('local')->put($project->output_path, 'mp4');

        $freed = app(MediaRetention::class)->prune($project->refresh());

        $this->assertFalse($freed['sources']);
        $this->assertFalse($freed['output']);
        Storage::disk('local')->assertExists($project->source_audio_path);
        Storage::disk('local')->assertExists($project->output_path);
    }

    #[Test]
    public function once_both_have_finished_the_output_can_go(): void
    {
        $user = User::factory()->create();
        $project = $this->renderable($user);
        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Uploaded,
            'youtube_video_id' => 'abc123',
        ])->save();

        Storage::disk('local')->put($project->output_path, 'mp4');

        $freed = app(MediaRetention::class)->prune($project->refresh());

        $this->assertTrue($freed['sources']);
        $this->assertTrue($freed['output']);
    }
}
