<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\GoogleService;
use App\Exceptions\Google\DriveStorageExhaustedException;
use App\Jobs\UploadVideoToGoogleDriveJob;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\DriveBackupManager;
use App\Services\Google\GoogleDriveService;
use App\Services\Media\MediaRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Holding more than one Drive account, and managing what is in them.
 *
 * Two things are being proved here, and they are different in kind from the
 * arithmetic in DriveStoragePoolTest:
 *
 *   **The schema actually permits it.** One Drive account per user was a
 *   database constraint, and relaxing a unique index is the kind of change
 *   that either works or silently takes a guarantee with it — so the YouTube
 *   half of that guarantee is asserted too.
 *
 *   **Backups can be managed, with the two sides kept in step.** Trashing a
 *   file that a project still claims would leave the studio reporting a
 *   backup that is in the bin.
 */
class DriveMultiAccountTest extends TestCase
{
    use RefreshDatabase;

    private const GIB = 1073741824;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        config()->set('services.drive.reserve_bytes', self::GIB);
        config()->set('services.google.clients.drive', [
            'client_id' => 'dr-id',
            'client_secret' => 'dr-secret',
            'redirect_uri' => 'https://keje.test/callback/drive',
        ]);
    }

    private function account(string $email, int $priority = 1, array $extra = []): GoogleConnection
    {
        return GoogleConnection::query()->create([
            'user_id' => $this->user->id,
            'service' => GoogleService::Drive,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'google_account_email' => $email,
            'priority' => $priority,
            'storage_limit' => 15 * self::GIB,
            'storage_usage' => 0,
            'storage_checked_at' => now(),
            ...$extra,
        ]);
    }

    // ── The schema ──────────────────────────────────────────────────────────

    #[Test]
    public function a_user_can_hold_several_drive_accounts(): void
    {
        $this->account('one@example.test', 1);
        $this->account('two@example.test', 2);
        $this->account('three@example.test', 3);

        $this->assertCount(3, $this->user->driveAccounts());
    }

    #[Test]
    public function the_same_drive_account_cannot_be_stored_twice(): void
    {
        // The database backstop. Two rows for one Google account would show
        // the same quota twice and double the pool's apparent free space —
        // which is precisely the number somebody is about to trust.
        $this->account('one@example.test', 1);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->account('one@example.test', 2);
    }

    #[Test]
    public function youtube_is_still_one_connection_per_user(): void
    {
        /*
         * The guarantee that relaxing the unique index could have quietly
         * taken away. Keying on the email alone would have let two YouTube
         * rows with no email recorded both be accepted, because MySQL permits
         * duplicate NULLs in a unique index — and a second channel is how a
         * lecture gets published to the wrong one.
         */
        GoogleConnection::query()->create([
            'user_id' => $this->user->id,
            'service' => GoogleService::YouTube,
            'refresh_token' => 'refresh',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        GoogleConnection::query()->create([
            'user_id' => $this->user->id,
            'service' => GoogleService::YouTube,
            'refresh_token' => 'another',
        ]);
    }

    #[Test]
    public function two_unidentified_drive_accounts_do_not_collide(): void
    {
        // The email is unknown until Google is asked, and two accounts
        // mid-identification must not be refused as duplicates of each other.
        $first = $this->account('', 1, ['google_account_email' => null]);
        $second = $this->account('', 2, ['google_account_email' => null]);

        $this->assertNotSame($first->account_key, $second->account_key);
        $this->assertCount(2, $this->user->driveAccounts());
    }

    #[Test]
    public function accounts_are_always_returned_in_fill_order(): void
    {
        // Not cosmetic: "the first account" decides where the next backup
        // goes, and an unordered read would move it between two uploads for
        // no reason anybody could see.
        $this->account('third@example.test', 3);
        $this->account('first@example.test', 1);
        $this->account('second@example.test', 2);

        $this->assertSame(
            ['first@example.test', 'second@example.test', 'third@example.test'],
            $this->user->driveAccounts()->pluck('google_account_email')->all(),
        );

        $this->assertSame(
            'first@example.test',
            $this->user->googleConnectionFor(GoogleService::Drive)?->google_account_email,
        );
    }

    // ── Uploading to the right one ──────────────────────────────────────────

    #[Test]
    public function a_backup_records_which_account_holds_it(): void
    {
        $account = $this->account('one@example.test', 1);
        $project = $this->renderedProject();

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('accountFor')->once()->andReturn($account);
        $drive->shouldReceive('upload')->once()->andReturn([
            'id' => 'file-1',
            'name' => 'kajian.mp4',
            'web_view_link' => null,
        ]);

        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive, app(MediaRetention::class));

        // Without this, a rename or a delete has no idea whose token to use.
        $this->assertSame($account->id, $project->refresh()->drive_connection_id);
    }

    #[Test]
    public function a_backup_too_big_for_any_account_is_not_retried(): void
    {
        /*
         * Every other backup failure is worth a retry — a blip, a stale
         * token, a transient Google error. This one never is: three more
         * attempts will re-measure every account and fail identically. The
         * message carries the shortfall and the fix instead.
         */
        $project = $this->renderedProject();

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('accountFor')->once()->andThrow(
            new DriveStorageExhaustedException(
                'This backup needs 2.0 GB and the roomiest connected Drive account has 1.0 GB free.',
                requiredBytes: 2 * self::GIB,
                largestFreeBytes: self::GIB,
            ),
        );
        $drive->shouldNotReceive('upload');

        // Does not throw: throwing is what would put the job through $tries.
        (new UploadVideoToGoogleDriveJob($project->id))->handle($drive, app(MediaRetention::class));

        $project->refresh();
        $this->assertSame(DriveStatus::Failed, $project->drive_status);
        $this->assertStringContainsString('roomiest connected Drive account', (string) $project->drive_error);
    }

    // ── Managing the backups ────────────────────────────────────────────────

    #[Test]
    public function renaming_a_backup_keeps_the_projects_record_in_step(): void
    {
        $account = $this->account('one@example.test', 1);
        $project = $this->backedUpProject($account, 'file-1', 'old-name.mp4');

        $manager = $this->fakeManager(folderId: 'folder-1', fileParents: ['folder-1']);

        $manager->rename($this->user, $account, 'file-1', 'new-name.mp4');

        // Letting these drift would leave the project page naming a file that
        // no longer exists under that name.
        $this->assertSame('new-name.mp4', $project->refresh()->drive_file_name);
    }

    #[Test]
    public function trashing_a_backup_clears_the_projects_drive_columns(): void
    {
        $account = $this->account('one@example.test', 1);
        $project = $this->backedUpProject($account, 'file-1', 'kajian.mp4');

        $manager = $this->fakeManager(folderId: 'folder-1', fileParents: ['folder-1']);

        $manager->trash($this->user, $account, 'file-1');

        $project->refresh();
        // The project honestly reads as not backed up, and can be again.
        $this->assertSame(DriveStatus::Pending, $project->drive_status);
        $this->assertNull($project->drive_file_id);
        $this->assertNull($project->drive_connection_id);
        $this->assertNull($project->drive_uploaded_at);
    }

    #[Test]
    public function a_file_outside_the_backup_folder_is_refused(): void
    {
        /*
         * drive.file already limits a token to files Keje created, which
         * bounds this a long way. Without the folder check as well, a valid id
         * for anything else Keje ever created in that account would be
         * renameable and trashable through the backups endpoints.
         */
        $account = $this->account('one@example.test', 1);

        $manager = $this->fakeManager(folderId: 'folder-1', fileParents: ['somewhere-else']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not one of this account');

        $manager->trash($this->user, $account, 'file-1');
    }

    #[Test]
    public function removing_a_backup_whose_account_is_gone_says_so(): void
    {
        /*
         * The file is still in whoever's Drive it was, and Keje holds no
         * token for it. Claiming a deletion that did not happen would be
         * worse than refusing: the project would read as not backed up while
         * a copy quietly consumed somebody's quota forever.
         */
        $account = $this->account('one@example.test', 1);
        $project = $this->backedUpProject($account, 'file-1', 'kajian.mp4');

        $account->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer connected');

        app(DriveBackupManager::class)->removeForProject($project->refresh());
    }

    #[Test]
    public function disconnecting_one_account_leaves_the_others_and_their_backups(): void
    {
        $keep = $this->account('keep@example.test', 1);
        $drop = $this->account('drop@example.test', 2);
        $project = $this->backedUpProject($drop, 'file-1', 'kajian.mp4');

        $this->deleteJson("/api/v1/integrations/drive/accounts/{$drop->uuid}")
            ->assertOk()
            ->assertJsonPath('data.totals.accounts', 1);

        $this->assertNotNull($keep->fresh());

        $project->refresh();
        // The link is gone and the file id survives, so the studio can say the
        // backup exists somewhere Keje can no longer reach.
        $this->assertNull($project->drive_connection_id);
        $this->assertSame('file-1', $project->drive_file_id);
    }

    #[Test]
    public function disconnecting_says_how_many_backups_it_strands(): void
    {
        $drop = $this->account('drop@example.test', 1);
        $this->backedUpProject($drop, 'file-1', 'one.mp4');
        $this->backedUpProject($drop, 'file-2', 'two.mp4');

        $this->deleteJson("/api/v1/integrations/drive/accounts/{$drop->uuid}")
            ->assertOk()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '2 backups are'));
    }

    #[Test]
    public function the_project_endpoint_refuses_when_there_is_no_backup(): void
    {
        $project = $this->renderedProject();

        $this->deleteJson("/api/v1/content-projects/{$project->uuid}/drive")
            ->assertStatus(422)
            ->assertJsonPath('error', 'drive_refused');
    }

    #[Test]
    public function another_users_project_backup_is_not_removable(): void
    {
        $stranger = User::factory()->create();
        $project = ContentProject::factory()->create([
            'user_id' => $stranger->id,
            'output_path' => 'content/x/renders/output.mp4',
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'file-1',
        ]);

        $this->deleteJson("/api/v1/content-projects/{$project->uuid}/drive")->assertStatus(404);

        $this->assertSame('file-1', $project->refresh()->drive_file_id);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A project with a render that is really on the faked disk.
     *
     * The file has to exist: the job checks for it before anything else and
     * returns early if it is missing, which would make these tests pass or
     * fail for a reason that has nothing to do with accounts.
     */
    private function renderedProject(int $size = 1024): ContentProject
    {
        $project = ContentProject::factory()->create([
            'user_id' => $this->user->id,
            'drive_status' => DriveStatus::Pending,
        ]);

        $path = "content/{$project->uuid}/renders/output.mp4";
        Storage::disk('local')->put($path, str_repeat('x', min($size, 1024)));

        $project->forceFill(['output_path' => $path, 'output_size' => $size])->save();

        return $project;
    }

    private function backedUpProject(GoogleConnection $account, string $fileId, string $name): ContentProject
    {
        return ContentProject::factory()->create([
            'user_id' => $this->user->id,
            'output_path' => 'content/'.bin2hex(random_bytes(6)).'/renders/output.mp4',
            'output_size' => 1024,
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => $fileId,
            'drive_file_name' => $name,
            'drive_connection_id' => $account->id,
            'drive_uploaded_at' => now(),
        ]);
    }

    /**
     * A backup manager whose Drive calls are canned.
     *
     * Only the catalog lookup and the file API are faked — the authorisation
     * logic, which is the part worth testing, runs for real.
     */
    private function fakeManager(string $folderId, array $fileParents): DriveBackupManager
    {
        $catalog = Mockery::mock(\App\Services\Google\DriveCatalogService::class);
        $catalog->shouldReceive('backupFolderForConnection')->andReturn(['id' => $folderId]);

        $file = Mockery::mock();
        $file->shouldReceive('getParents')->andReturn($fileParents);
        $file->shouldReceive('getTrashed')->andReturn(false);

        $updated = Mockery::mock(\Google\Service\Drive\DriveFile::class);
        $updated->shouldReceive('getId')->andReturn('file-1');
        $updated->shouldReceive('getName')->andReturn('new-name.mp4');
        $updated->shouldReceive('getMimeType')->andReturn('video/mp4');
        $updated->shouldReceive('getSize')->andReturn(1024);
        $updated->shouldReceive('getCreatedTime')->andReturn(null);
        $updated->shouldReceive('getModifiedTime')->andReturn(null);
        $updated->shouldReceive('getWebViewLink')->andReturn(null);

        $files = Mockery::mock();
        $files->shouldReceive('get')->andReturn($file);
        $files->shouldReceive('update')->andReturn($updated);

        $drive = Mockery::mock(\Google\Service\Drive::class);
        $drive->files = $files;

        $clients = Mockery::mock(\App\Services\Google\GoogleClientFactory::class);
        $clients->shouldReceive('forConnection')->andReturn(new \Google\Client);

        return new class($clients, $catalog, $drive) extends DriveBackupManager
        {
            public function __construct(
                $clients,
                $catalog,
                private readonly mixed $fakeDrive,
            ) {
                parent::__construct($clients, $catalog);
            }

            protected function driveFor(GoogleConnection $account): \Google\Service\Drive
            {
                return $this->fakeDrive;
            }
        };
    }
}
