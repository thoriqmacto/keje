<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\User;
use App\Services\Media\MediaRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Local retention: free the VPS once Drive holds the render.
 *
 * The invariants worth guarding are the destructive ones — nothing goes
 * without a confirmed Drive copy, and the MP4 survives while the YouTube
 * pipeline could still need it.
 */
class MediaRetentionTest extends TestCase
{
    use RefreshDatabase;

    private MediaRetention $retention;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->retention = app(MediaRetention::class);

        config([
            'media.retention.prune_sources_after_backup' => true,
            'media.retention.prune_output_after_backup' => true,
            'media.retention.retain_output_for_youtube' => true,
        ]);
    }

    /** A rendered project with every file on disk. */
    private function project(array $overrides = []): ContentProject
    {
        $user = User::factory()->create();
        $project = ContentProject::factory()->withMedia()->create(['user_id' => $user->id]);

        $dir = $project->storageDirectory();
        $disk = Storage::disk('local');

        $disk->put("{$dir}/source/audio.mp3", str_repeat('a', 4096));
        $disk->put("{$dir}/source/background.jpg", str_repeat('b', 1024));
        $disk->put("{$dir}/text/title.txt", 'Keutamaan Lapar');
        $disk->put("{$dir}/renders/output.mp4", str_repeat('c', 2048));

        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'source_audio_path' => "{$dir}/source/audio.mp3",
            'background_image_path' => "{$dir}/source/background.jpg",
            'output_path' => "{$dir}/renders/output.mp4",
            'output_size' => 2048,
            ...$overrides,
        ])->save();

        return $project->refresh();
    }

    private function backedUp(array $overrides = []): ContentProject
    {
        return $this->project([
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-file-123',
            'drive_uploaded_at' => now(),
            ...$overrides,
        ]);
    }

    // ── The safety invariant ────────────────────────────────────────────────

    #[Test]
    public function nothing_is_deleted_without_a_confirmed_drive_backup(): void
    {
        $project = $this->project(['drive_status' => DriveStatus::Pending]);

        $this->retention->prune($project);

        $disk = Storage::disk('local');
        $disk->assertExists($project->source_audio_path);
        $disk->assertExists($project->output_path);
        $this->assertNull($project->refresh()->media_pruned_at);
    }

    #[Test]
    public function a_failed_backup_prunes_nothing(): void
    {
        $project = $this->project([
            'drive_status' => DriveStatus::Failed,
            'drive_error' => 'quota exceeded',
        ]);

        $this->retention->prune($project);

        Storage::disk('local')->assertExists($project->source_audio_path);
    }

    #[Test]
    public function an_uploaded_status_without_a_file_id_is_not_trusted(): void
    {
        // Half-written state: the status says uploaded but Drive gave us no id,
        // so we cannot point at a copy and must not delete the original.
        $project = $this->project([
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => null,
        ]);

        $this->retention->prune($project);

        Storage::disk('local')->assertExists($project->source_audio_path);
    }

    // ── What pruning actually removes ───────────────────────────────────────

    #[Test]
    public function sources_and_scratch_go_once_the_render_is_backed_up(): void
    {
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Uploaded]);
        $dir = $project->storageDirectory();

        $freed = $this->retention->prune($project);

        $disk = Storage::disk('local');
        $disk->assertMissing("{$dir}/source/audio.mp3");
        $disk->assertMissing("{$dir}/source/background.jpg");
        $disk->assertMissing("{$dir}/text/title.txt");

        $this->assertTrue($freed['sources']);
        $this->assertSame(4096 + 1024 + 2048 + 15, $freed['bytes']);
    }

    #[Test]
    public function the_text_side_of_the_project_survives(): void
    {
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Uploaded]);
        $title = $project->primary_title;
        $audioName = $project->source_audio_original_name;
        $duration = $project->source_audio_duration;

        $this->retention->prune($project);
        $project->refresh();

        // "content like text can stay" — only the bytes go.
        $this->assertSame($title, $project->primary_title);
        $this->assertSame($audioName, $project->source_audio_original_name);
        $this->assertSame($duration, $project->source_audio_duration);
        $this->assertSame(2048, $project->output_size);
        $this->assertNotNull($project->media_pruned_at);
    }

    #[Test]
    public function the_project_still_points_at_its_drive_copy(): void
    {
        $project = $this->backedUp([
            'youtube_status' => YouTubeStatus::Uploaded,
            'drive_web_view_link' => 'https://drive.google.com/file/d/drive-file-123/view',
        ]);

        $this->retention->prune($project);
        $project->refresh();

        $this->assertSame('drive-file-123', $project->drive_file_id);
        $this->assertSame(
            'https://drive.google.com/file/d/drive-file-123/view',
            $project->drive_web_view_link,
        );
        $this->assertSame(DriveStatus::Uploaded, $project->drive_status);
    }

    // ── The YouTube interaction ─────────────────────────────────────────────

    #[Test]
    public function the_mp4_is_kept_while_youtube_might_still_upload_it(): void
    {
        // YouTube reads the same local file. Deleting it the moment Drive
        // finishes would make publishing impossible.
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Pending]);

        $freed = $this->retention->prune($project);
        $project->refresh();

        Storage::disk('local')->assertExists("{$project->storageDirectory()}/renders/output.mp4");
        $this->assertFalse($freed['output']);
        $this->assertNotNull($project->output_path);

        // Sources are unaffected — YouTube never reads them.
        $this->assertTrue($freed['sources']);
        $this->assertNull($project->source_audio_path);
    }

    #[Test]
    public function the_mp4_goes_once_youtube_holds_a_copy(): void
    {
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Scheduled]);

        $freed = $this->retention->prune($project);
        $project->refresh();

        Storage::disk('local')->assertMissing("{$project->storageDirectory()}/renders/output.mp4");
        $this->assertTrue($freed['output']);
        $this->assertNull($project->output_path);
    }

    #[Test]
    public function drive_only_setups_can_release_the_mp4_immediately(): void
    {
        config(['media.retention.retain_output_for_youtube' => false]);
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Pending]);

        $freed = $this->retention->prune($project);

        $this->assertTrue($freed['output']);
        Storage::disk('local')->assertMissing("{$project->storageDirectory()}/renders/output.mp4");
    }

    // ── Effect on the rest of the app ───────────────────────────────────────

    #[Test]
    public function a_pruned_project_can_no_longer_be_rendered(): void
    {
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Uploaded]);

        $this->assertTrue($project->isRenderable());

        $this->retention->prune($project);

        // hasRequiredMedia() turns false, so the existing render guard refuses
        // rather than the renderer failing on a missing file.
        $this->assertFalse($project->refresh()->isRenderable());
    }

    #[Test]
    public function retention_can_be_switched_off_entirely(): void
    {
        config([
            'media.retention.prune_sources_after_backup' => false,
            'media.retention.prune_output_after_backup' => false,
        ]);
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Uploaded]);

        $freed = $this->retention->prune($project);

        $this->assertSame(0, $freed['bytes']);
        Storage::disk('local')->assertExists($project->source_audio_path);
        Storage::disk('local')->assertExists($project->output_path);
        $this->assertNull($project->refresh()->media_pruned_at);
    }

    // ── The command ─────────────────────────────────────────────────────────

    #[Test]
    public function the_prune_command_reports_without_deleting_on_a_dry_run(): void
    {
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Uploaded]);

        $this->artisan('media:prune --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($project->source_audio_path);
        $this->assertNull($project->refresh()->media_pruned_at);
    }

    #[Test]
    public function the_prune_command_reclaims_space_from_existing_projects(): void
    {
        $project = $this->backedUp(['youtube_status' => YouTubeStatus::Uploaded]);

        $this->artisan('media:prune')->assertExitCode(0);

        Storage::disk('local')->assertMissing("{$project->storageDirectory()}/source/audio.mp3");
        $this->assertNotNull($project->refresh()->media_pruned_at);
    }

    #[Test]
    public function the_prune_command_leaves_unbacked_projects_alone(): void
    {
        $project = $this->project(['drive_status' => DriveStatus::Pending]);

        $this->artisan('media:prune')->assertExitCode(0);

        Storage::disk('local')->assertExists($project->source_audio_path);
    }
}
