<?php

namespace Tests\Feature\Studio;

use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\User;
use App\Services\Google\YouTubeThumbnailService;
use App\Services\Media\FfmpegService;
use App\Services\Media\VideoFrameExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing a frame as the YouTube thumbnail.
 *
 * The rule that matters most: a thumbnail retry must never be able to reach
 * videos.insert. A failed thumbnail on a published video is a thumbnail
 * problem, and answering it with another upload would put a second copy of
 * the lecture on the channel.
 */
class ThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function rendered(User $user, array $attributes = []): ContentProject
    {
        $project = ContentProject::factory()->withMediaFiles()->create([
            'user_id' => $user->id,
            'output_duration' => 600.0,
            ...$attributes,
        ]);

        $project->forceFill(['output_path' => $project->storageDirectory().'/renders/output.mp4'])->save();
        Storage::disk('local')->put($project->output_path, 'mp4');

        return $project->refresh();
    }

    /** Write a JPEG wherever FFmpeg was asked to, without running FFmpeg. */
    private function fakeFfmpeg(): void
    {
        $ffmpeg = Mockery::mock(FfmpegService::class);
        $ffmpeg->shouldReceive('run')->andReturnUsing(function (array $args) {
            file_put_contents(end($args), 'jpeg-bytes');

            return ['exit_code' => 0, 'log' => ''];
        });

        $this->instance(FfmpegService::class, $ffmpeg);
        $this->instance(VideoFrameExtractor::class, new VideoFrameExtractor($ffmpeg));
    }

    // ── Candidates ──────────────────────────────────────────────────────────

    #[Test]
    public function candidate_timestamps_avoid_the_first_and_last_frame(): void
    {
        // A lecture opens and closes on near-static artwork, so a frame from
        // either end is the title card the video already shows in a list.
        $extractor = app(VideoFrameExtractor::class);

        $this->assertSame([150.0, 300.0, 450.0], $extractor->candidateTimestamps(600.0));
        $this->assertSame([], $extractor->candidateTimestamps(0.0));
    }

    #[Test]
    public function generating_candidates_writes_three_frames(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.timestamp', 150);

        Storage::disk('local')->assertExists($project->storageDirectory().'/thumbnails/150.jpg');
    }

    #[Test]
    public function a_custom_timestamp_generates_a_single_frame(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames", [
            'timestamp' => 262.5,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Storage::disk('local')->assertExists($project->storageDirectory().'/thumbnails/262-5.jpg');
    }

    #[Test]
    public function a_timestamp_past_the_end_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames", [
            'timestamp' => 9999,
        ])->assertStatus(422)->assertJsonValidationErrors(['timestamp']);
    }

    #[Test]
    public function frames_need_a_rendered_video(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")
            ->assertStatus(422);
    }

    // ── Selection ───────────────────────────────────────────────────────────

    #[Test]
    public function selecting_a_generated_frame_persists_it(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")->assertOk();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/select", [
            'timestamp' => 300,
        ])
            ->assertOk()
            ->assertJsonPath('data.thumbnail.selected', true)
            ->assertJsonPath('data.thumbnail.timestamp', 300);
    }

    #[Test]
    public function selecting_a_frame_that_was_never_generated_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/select", [
            'timestamp' => 42,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_generated_frame_can_be_previewed(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")->assertOk();

        $this->get("/api/v1/content-projects/{$project->uuid}/thumbnail?timestamp=300")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    // ── Pushing to YouTube ──────────────────────────────────────────────────

    #[Test]
    public function pushing_calls_thumbnails_set_and_never_uploads_a_video(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user, [
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Uploaded,
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")->assertOk();
        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/select", ['timestamp' => 300]);

        $thumbnails = Mockery::mock(YouTubeThumbnailService::class);
        $thumbnails->shouldReceive('set')->once()->andReturn(['ok' => true, 'error' => null]);
        $this->instance(YouTubeThumbnailService::class, $thumbnails);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/push")
            ->assertOk()
            ->assertJsonPath('data.thumbnail.youtube_status', 'set');

        // The video is untouched: same id, still uploaded.
        $project->refresh();
        $this->assertSame('abc123', $project->youtube_video_id);
        $this->assertSame(YouTubeStatus::Uploaded, $project->youtube_status);
    }

    #[Test]
    public function a_thumbnail_failure_leaves_the_video_alone(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user, [
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Published,
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")->assertOk();
        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/select", ['timestamp' => 300]);

        $thumbnails = Mockery::mock(YouTubeThumbnailService::class);
        $thumbnails->shouldReceive('set')->andReturn([
            'ok' => false,
            'error' => 'This channel is not allowed to set custom thumbnails.',
        ]);
        $this->instance(YouTubeThumbnailService::class, $thumbnails);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/push")
            ->assertStatus(422)
            ->assertJsonPath('data.thumbnail.youtube_status', 'failed');

        // "YouTube: Failed" would be wrong — the video exists and is published.
        $project->refresh();
        $this->assertSame(YouTubeStatus::Published, $project->youtube_status);
        $this->assertSame('abc123', $project->youtube_video_id);
    }

    #[Test]
    public function pushing_without_a_video_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->rendered($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/push")
            ->assertStatus(422);
    }

    #[Test]
    public function pruning_the_render_keeps_the_chosen_thumbnail(): void
    {
        // A thumbnail is a few dozen kilobytes and is still needed after the
        // MP4 goes to Drive — for a retry, and to show what was chosen.
        Sanctum::actingAs($user = User::factory()->create());
        $this->fakeFfmpeg();
        $project = $this->rendered($user, [
            'youtube_video_id' => 'abc123',
            'youtube_status' => YouTubeStatus::Uploaded,
            'drive_status' => \App\Enums\DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")->assertOk();
        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/select", ['timestamp' => 300]);

        // The point here is that the thumbnail survives a prune, so the prune
        // has to actually happen: the correction window would otherwise keep
        // everything and the assertion would pass for the wrong reason.
        config(['media.retention.correction_window_days' => 0]);

        app(\App\Services\Media\MediaRetention::class)->prune($project->refresh());

        Storage::disk('local')->assertMissing($project->source_audio_path);
        Storage::disk('local')->assertExists($project->refresh()->thumbnail_path);
    }

    #[Test]
    public function another_users_project_is_not_reachable(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $project = $this->rendered(User::factory()->create());

        $this->postJson("/api/v1/content-projects/{$project->uuid}/thumbnail/frames")
            ->assertNotFound();
    }
}
