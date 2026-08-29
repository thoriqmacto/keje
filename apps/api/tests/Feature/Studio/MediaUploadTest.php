<?php

namespace Tests\Feature\Studio;

use App\Exceptions\Media\UnusableMediaException;
use App\Models\ContentProject;
use App\Models\User;
use App\Services\Media\FfprobeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Upload validation.
 *
 * ffprobe is mocked throughout — the point is the controller's handling of
 * what ffprobe reports, and normal test runs must not need FFmpeg installed.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function project(): ContentProject
    {
        Sanctum::actingAs($user = User::factory()->create());

        return ContentProject::factory()->create(['user_id' => $user->id]);
    }

    /** @param array<string, mixed> $audio */
    private function fakeProbe(?array $audio = null, ?UnusableMediaException $throws = null): void
    {
        $mock = Mockery::mock(FfprobeService::class);

        if ($throws !== null) {
            $mock->shouldReceive('inspectAudio')->andThrow($throws);
        } else {
            $mock->shouldReceive('inspectAudio')->andReturn($audio ?? [
                'codec' => 'mp3',
                'duration' => 1815.5,
                'sample_rate' => 44100,
                'channels' => 2,
                'bitrate' => 128000,
            ]);
        }

        $mock->shouldReceive('inspectImage')->andReturn([
            'width' => 1920, 'height' => 1080, 'codec' => 'mjpeg',
        ]);

        $this->instance(FfprobeService::class, $mock);
    }

    // ── Audio ───────────────────────────────────────────────────────────────

    #[Test]
    public function a_valid_mp3_is_accepted_and_its_metadata_recorded(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('lecture.mp3', 2048, 'audio/mpeg'),
        ])
            ->assertOk()
            ->assertJsonPath('data.source_audio.codec', 'mp3')
            ->assertJsonPath('data.source_audio.sample_rate', 44100)
            ->assertJsonPath('data.source_audio.original_name', 'lecture.mp3');

        $project->refresh();
        $this->assertSame(1815.5, $project->source_audio_duration);
        Storage::disk('local')->assertExists($project->source_audio_path);
    }

    #[Test]
    public function an_mpeg_recording_is_accepted_without_any_audacity_step(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('lecture.mpeg', 4096, 'video/mpeg'),
        ])->assertOk();
    }

    #[Test]
    public function a_file_with_no_audio_stream_is_rejected_and_not_kept(): void
    {
        $project = $this->project();
        $this->fakeProbe(throws: new UnusableMediaException('That file contains no audio track.'));

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('silent.mp3', 512, 'audio/mpeg'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio']);

        // A rejected upload must not leave a file or a half-updated project.
        $this->assertNull($project->refresh()->source_audio_path);
        $this->assertEmpty(Storage::disk('local')->allFiles($project->storageDirectory()));
    }

    #[Test]
    public function a_disallowed_extension_is_rejected(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio']);
    }

    #[Test]
    public function an_oversized_recording_is_rejected(): void
    {
        config(['media.max_audio_mb' => 1]);
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('huge.mp3', 4096, 'audio/mpeg'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio']);
    }

    #[Test]
    public function the_stored_filename_is_server_controlled(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('../../evil name.mp3', 128, 'audio/mpeg'),
        ])->assertOk();

        $path = $project->refresh()->source_audio_path;

        // The path is ours, built from the project UUID and a fixed basename.
        $this->assertSame("content/{$project->uuid}/source/audio.mp3", $path);
        // The original name survives only as a display label, and Symfony has
        // already stripped the traversal segments from it.
        $this->assertSame('evil name.mp3', $project->source_audio_original_name);
        $this->assertStringNotContainsString('..', $path);
    }

    // ── Background ──────────────────────────────────────────────────────────

    #[Test]
    public function a_valid_background_image_is_accepted(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/background", [
            'background' => UploadedFile::fake()->image('artwork.jpg', 1920, 1080),
        ])
            ->assertOk()
            ->assertJsonPath('data.background_image.width', 1920)
            ->assertJsonPath('data.background_image.height', 1080);

        Storage::disk('local')->assertExists($project->refresh()->background_image_path);
    }

    #[Test]
    public function a_non_image_background_is_rejected(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/background", [
            'background' => UploadedFile::fake()->create('artwork.jpg', 64, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['background']);
    }

    // ── State ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_project_becomes_media_ready_only_once_both_files_exist(): void
    {
        $project = $this->project();
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('lecture.mp3', 128, 'audio/mpeg'),
        ])->assertOk()->assertJsonPath('data.render.status', 'draft');

        $this->postJson("/api/v1/content-projects/{$project->uuid}/background", [
            'background' => UploadedFile::fake()->image('artwork.jpg', 1920, 1080),
        ])->assertOk()->assertJsonPath('data.render.status', 'media_ready');
    }

    #[Test]
    public function uploads_to_another_users_project_are_refused(): void
    {
        $theirs = ContentProject::factory()->create(['user_id' => User::factory()->create()->id]);
        Sanctum::actingAs(User::factory()->create());
        $this->fakeProbe();

        $this->postJson("/api/v1/content-projects/{$theirs->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('lecture.mp3', 128, 'audio/mpeg'),
        ])->assertStatus(404);
    }

    #[Test]
    public function unauthenticated_uploads_are_rejected(): void
    {
        $project = ContentProject::factory()->create();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/audio", [
            'audio' => UploadedFile::fake()->create('lecture.mp3', 128, 'audio/mpeg'),
        ])->assertStatus(401);
    }
}
