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
            // These cases are about the Drive-backup invariant. The correction
            // window is a separate policy with its own tests below, and
            // leaving it on here would mean every one of them was really
            // testing the window instead.
            'media.retention.correction_window_days' => 0,
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

    /*
     * ── The correction window ───────────────────────────────────────────────
     *
     * Publishing used to delete the working files the instant YouTube
     * confirmed the upload — which is precisely the moment the video becomes
     * visible and its mistakes become findable. Someone who noticed a wrong
     * speaker name the next morning had to find the original recording again,
     * if they still had it.
     *
     * These cases pin the window that keeps "publish, spot a mistake, fix it"
     * possible, and the explicit finalisation that ends it.
     */

    #[Test]
    public function a_freshly_published_project_keeps_its_working_files(): void
    {
        config(['media.retention.correction_window_days' => 14]);

        $project = $this->backedUp([
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subHours(2),
        ]);

        $freed = $this->retention->prune($project->refresh());

        $this->assertFalse($freed['sources']);
        $this->assertFalse($freed['output']);
        // Both are needed: correcting means re-rendering, and re-rendering
        // needs the recording as well as somewhere to put the result.
        Storage::disk('local')->assertExists($project->source_audio_path);
        Storage::disk('local')->assertExists($project->output_path);
    }

    #[Test]
    public function the_window_closes_once_it_has_elapsed(): void
    {
        config(['media.retention.correction_window_days' => 14]);

        $project = $this->backedUp([
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subDays(15),
        ]);

        $freed = $this->retention->prune($project->refresh());

        $this->assertTrue($freed['sources']);
        $this->assertTrue($freed['output']);
    }

    #[Test]
    public function finalising_ends_the_window_immediately(): void
    {
        config(['media.retention.correction_window_days' => 14]);

        $project = $this->backedUp([
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subHour(),
        ]);

        // Inside the window, so nothing goes yet.
        $this->assertFalse($this->retention->prune($project->refresh())['output']);

        // The user has watched it and says it is right. They know that better
        // than a clock does, which is why this exists alongside the window.
        $project->forceFill(['finalized_at' => now()])->save();

        $this->assertTrue($this->retention->prune($project->refresh())['output']);
    }

    #[Test]
    public function a_pending_replacement_protects_the_render_it_is_uploading(): void
    {
        // Zero window: only the replacement itself is keeping this file.
        config(['media.retention.correction_window_days' => 0]);

        $project = $this->backedUp([
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'OLD123',
            'youtube_uploaded_at' => now()->subMonth(),
        ]);

        \App\Models\YouTubeReplacement::create([
            'content_project_id' => $project->id,
            'user_id' => $project->user_id,
            'status' => \App\Enums\ReplacementStatus::Uploading,
            'active_key' => $project->id,
            'old_video_id' => 'OLD123',
            'old_disposition' => \App\Enums\OldVideoDisposition::Delete,
        ]);

        $freed = $this->retention->prune($project->refresh());

        // Deleting this mid-workflow would strand a correction that cannot be
        // retried: the file is the upload's source.
        $this->assertFalse($freed['output']);
        Storage::disk('local')->assertExists($project->output_path);
    }

    #[Test]
    public function the_expiry_sweep_skips_projects_still_inside_their_window(): void
    {
        config(['media.retention.correction_window_days' => 14]);

        $recent = $this->backedUp([
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'recent',
            'youtube_uploaded_at' => now()->subDay(),
        ]);

        $this->artisan('media:prune-expired')->assertExitCode(0);

        Storage::disk('local')->assertExists($recent->refresh()->output_path);
    }

    #[Test]
    public function the_expiry_sweep_collects_projects_past_their_window(): void
    {
        config(['media.retention.correction_window_days' => 14]);

        $old = $this->backedUp([
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'old',
            'youtube_uploaded_at' => now()->subDays(30),
        ]);

        $this->artisan('media:prune-expired')->assertExitCode(0);

        $this->assertNull($old->refresh()->output_path);
    }

    #[Test]
    public function the_expiry_sweep_never_touches_an_unbacked_project(): void
    {
        config(['media.retention.correction_window_days' => 0]);

        // The oldest invariant in these rules, and the sweeper does not get to
        // relax it: no Drive copy, no deletion, however old the project is.
        $project = $this->project([
            'drive_status' => DriveStatus::Pending,
            'youtube_status' => YouTubeStatus::Published,
            'youtube_uploaded_at' => now()->subYear(),
        ]);

        $this->artisan('media:prune-expired')->assertExitCode(0);

        Storage::disk('local')->assertExists($project->source_audio_path);
    }
}
