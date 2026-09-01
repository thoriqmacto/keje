<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Storage page's data, and the boundary around it.
 *
 * Two things are being tested. The first is arithmetic: the page exists to say
 * how much disk Keje is using, and a number derived from database columns
 * rather than the files would be confidently wrong exactly when it matters —
 * after a failed prune, or a restore that brought files back without rows.
 *
 * The second is the boundary. This reads the filesystem, which makes it the
 * one place in the app where "show me a path" could turn into a remote file
 * browser. Nothing here takes a path from anywhere, and none of the responses
 * carry an absolute one.
 */
class MediaInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        config(['media.retention.correction_window_days' => 14]);
    }

    /** A project with real bytes on the fake disk, in named categories. */
    private function withFiles(array $sizes, array $attributes = []): ContentProject
    {
        $project = ContentProject::factory()->create([
            'user_id' => $this->user->id,
            ...$attributes,
        ]);

        $dir = $project->storageDirectory();
        $disk = Storage::disk('local');

        $map = [
            'sources' => "{$dir}/source/audio.mp3",
            'renders' => "{$dir}/renders/output.mp4",
            'thumbnails' => "{$dir}/thumbs/frame.jpg",
            'text' => "{$dir}/text/title.txt",
            'temp' => "{$dir}/temp/pass.log",
        ];

        foreach ($sizes as $category => $bytes) {
            $disk->put($map[$category], str_repeat('x', $bytes));
        }

        return $project->refresh();
    }

    private function inventory(): array
    {
        return $this->getJson('/api/v1/storage')->assertOk()->json('data');
    }

    // ── Measurement ─────────────────────────────────────────────────────────

    #[Test]
    public function files_are_counted_into_the_category_they_sit_in(): void
    {
        $this->withFiles([
            'sources' => 1000,
            'renders' => 2000,
            'thumbnails' => 300,
            'text' => 40,
            'temp' => 60,
        ]);

        $totals = $this->inventory()['totals'];

        $this->assertSame(1000, $totals['sources']);
        $this->assertSame(2000, $totals['renders']);
        $this->assertSame(300, $totals['thumbnails']);
        $this->assertSame(40, $totals['text']);
        $this->assertSame(60, $totals['temp']);
        $this->assertSame(3400, $totals['total']);
    }

    #[Test]
    public function sizes_come_from_the_disk_not_from_database_columns(): void
    {
        // The columns claim four megabytes; the disk holds ten bytes. After a
        // failed prune or a partial restore the two disagree, and the disk is
        // the side that fills up.
        $project = $this->withFiles(['sources' => 10]);
        $project->forceFill(['source_audio_size' => 4_000_000])->save();

        $this->assertSame(10, $this->inventory()['totals']['sources']);
    }

    #[Test]
    public function a_project_with_a_row_but_no_files_is_left_out(): void
    {
        // Already pruned. Listing it on a page about disk usage would be
        // noise: it is using none.
        ContentProject::factory()->create(['user_id' => $this->user->id]);

        $this->assertSame([], $this->inventory()['projects']);
        $this->assertSame(0, $this->inventory()['totals']['total']);
    }

    #[Test]
    public function another_users_media_is_never_counted(): void
    {
        $other = ContentProject::factory()->create(['user_id' => User::factory()->create()->id]);
        Storage::disk('local')->put("{$other->storageDirectory()}/renders/output.mp4", str_repeat('x', 5000));

        $this->withFiles(['sources' => 100]);

        $this->assertSame(100, $this->inventory()['totals']['total']);
    }

    #[Test]
    public function no_absolute_server_path_reaches_the_browser(): void
    {
        $this->withFiles(['sources' => 100, 'renders' => 200]);

        $body = $this->getJson('/api/v1/storage')->assertOk()->getContent();

        // The one place in the app that reads the filesystem is the one place
        // that must not describe it.
        $this->assertStringNotContainsString(storage_path(), $body);
        $this->assertStringNotContainsString('/var/www', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }

    // ── Orphans ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_managed_directory_with_no_project_is_reported_as_orphaned(): void
    {
        $orphan = '11111111-2222-3333-4444-555555555555';
        Storage::disk('local')->put("content/{$orphan}/renders/output.mp4", str_repeat('x', 800));

        $inventory = $this->inventory();

        $this->assertCount(1, $inventory['orphans']);
        $this->assertSame($orphan, $inventory['orphans'][0]['id']);
        $this->assertSame(800, $inventory['orphans'][0]['bytes']);
    }

    #[Test]
    public function orphans_are_reported_and_never_removed(): void
    {
        $orphan = '11111111-2222-3333-4444-555555555555';
        $path = "content/{$orphan}/renders/output.mp4";
        Storage::disk('local')->put($path, str_repeat('x', 800));

        $this->getJson('/api/v1/storage')->assertOk();
        $this->postJson('/api/v1/storage/prune')->assertOk();

        // An unreferenced directory is as likely to be a database problem as a
        // disk one, and deleting media to tidy a listing is the wrong way
        // round.
        Storage::disk('local')->assertExists($path);
    }

    #[Test]
    public function a_directory_that_is_not_ours_is_not_reported(): void
    {
        // Only UUID-named directories are Keje's. Anything else under the
        // prefix is not ours to describe, let alone offer to delete.
        Storage::disk('local')->put('content/not-a-uuid/file.bin', 'x');

        $this->assertSame([], $this->inventory()['orphans']);
    }

    // ── Eligibility, explained ──────────────────────────────────────────────

    #[Test]
    public function a_project_without_a_drive_backup_says_so(): void
    {
        $this->withFiles(['sources' => 100], ['drive_status' => DriveStatus::Pending]);

        $row = $this->inventory()['projects'][0];

        $this->assertFalse($row['prunable']);
        $this->assertSame('no_backup', $row['blocked_reasons'][0]['code']);
    }

    #[Test]
    public function a_project_inside_its_correction_window_says_how_long_is_left(): void
    {
        $this->withFiles(['sources' => 100], [
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subDays(5),
        ]);

        $row = $this->inventory()['projects'][0];

        $this->assertFalse($row['prunable']);
        $this->assertSame('correction_window', $row['blocked_reasons'][0]['code']);
        // "Not eligible" with no reason is not an explanation.
        $this->assertStringContainsString('day', $row['blocked_reasons'][0]['message']);
        $this->assertNotNull($row['correction_days_remaining']);
    }

    #[Test]
    public function an_outdated_render_explains_why_its_sources_are_kept(): void
    {
        $project = $this->withFiles(['sources' => 100, 'renders' => 200], [
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subDays(2),
            'render_status' => RenderStatus::Rendered,
        ]);

        $project->forceFill([
            'output_path' => "{$project->storageDirectory()}/renders/output.mp4",
            'last_render_input_hash' => str_repeat('0', 64),
        ])->save();

        $row = $this->inventory()['projects'][0];

        $this->assertTrue($row['render_is_stale']);
        $codes = array_column($row['blocked_reasons'], 'code');
        $this->assertContains('render_outdated', $codes);
    }

    #[Test]
    public function a_finished_backed_up_project_is_prunable(): void
    {
        $this->withFiles(['sources' => 100, 'renders' => 200], [
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subMonths(2),
            'finalized_at' => now(),
        ]);

        $row = $this->inventory()['projects'][0];

        $this->assertTrue($row['prunable']);
        $this->assertSame([], $row['blocked_reasons']);
    }

    // ── Preview and prune ───────────────────────────────────────────────────

    #[Test]
    public function the_preview_counts_only_what_the_prune_would_free(): void
    {
        $this->withFiles(['sources' => 100, 'renders' => 200, 'thumbnails' => 50], [
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subMonths(2),
            'finalized_at' => now(),
        ]);

        $preview = $this->getJson('/api/v1/storage/prune-preview')->assertOk()->json('data');

        $this->assertCount(1, $preview['eligible']);
        // Thumbnails survive a prune, so promising their bytes back would be
        // a preview the action then declines to honour.
        $this->assertSame(300, $preview['bytes']['total']);
    }

    #[Test]
    public function the_preview_names_why_each_skipped_project_was_skipped(): void
    {
        $this->withFiles(['sources' => 100], ['drive_status' => DriveStatus::Pending]);

        $preview = $this->getJson('/api/v1/storage/prune-preview')->assertOk()->json('data');

        $this->assertCount(1, $preview['skipped']);
        $this->assertSame('no_backup', $preview['skipped'][0]['reasons'][0]['code']);
        $this->assertSame(0, $preview['bytes']['total']);
    }

    #[Test]
    public function pruning_removes_the_eligible_and_leaves_the_rest(): void
    {
        $safe = $this->withFiles(['sources' => 100, 'renders' => 200], [
            'working_title' => 'Finished',
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subMonths(2),
            'finalized_at' => now(),
        ]);
        $safe->forceFill([
            'source_audio_path' => "{$safe->storageDirectory()}/source/audio.mp3",
            'output_path' => "{$safe->storageDirectory()}/renders/output.mp4",
        ])->save();

        $blocked = $this->withFiles(['sources' => 100], [
            'working_title' => 'No backup',
            'drive_status' => DriveStatus::Pending,
        ]);
        // A null path column is what "already pruned" looks like, and the
        // prune rightly skips those — so this has to point at its file to be
        // a candidate the prune then declines.
        $blocked->forceFill([
            'source_audio_path' => "{$blocked->storageDirectory()}/source/audio.mp3",
        ])->save();

        $this->postJson('/api/v1/storage/prune')
            ->assertOk()
            ->assertJsonPath('data.pruned', 1)
            ->assertJsonPath('data.skipped', 1);

        Storage::disk('local')->assertMissing("{$safe->storageDirectory()}/source/audio.mp3");
        Storage::disk('local')->assertExists("{$blocked->storageDirectory()}/source/audio.mp3");
    }

    #[Test]
    public function the_preview_removes_nothing(): void
    {
        $project = $this->withFiles(['sources' => 100], [
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'abc123',
            'youtube_uploaded_at' => now()->subMonths(2),
            'finalized_at' => now(),
        ]);

        $this->getJson('/api/v1/storage/prune-preview')->assertOk();

        Storage::disk('local')->assertExists("{$project->storageDirectory()}/source/audio.mp3");
    }

    #[Test]
    public function the_storage_endpoints_require_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/storage')->assertUnauthorized();
        $this->postJson('/api/v1/storage/prune')->assertUnauthorized();
    }
}
