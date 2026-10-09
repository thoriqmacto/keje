<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\GoogleService;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\DriveStoragePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Several Drive accounts, treated as one pool of space.
 *
 * The problem being solved: a free Google account holds fifteen gigabytes
 * shared with Gmail and Photos, and a rendered lecture is a few hundred
 * megabytes. So the ceiling on how much of a course Keje can keep was never a
 * Keje limit — it was one account's quota, with nothing to do about it but
 * stop backing things up.
 *
 * The arithmetic is tested without Google, which is the point of keeping
 * DriveQuotaSync separate: what matters here is not what about.get returns,
 * it is what several accounts add up to and which of them a file should go
 * to.
 */
class DriveStoragePoolTest extends TestCase
{
    use RefreshDatabase;

    private const GIB = 1073741824;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // A round reserve, so the sums in these tests are legible.
        config()->set('services.drive.reserve_bytes', self::GIB);
    }

    /**
     * @param  int|null  $limit  null means Google reported no limit
     */
    private function account(?int $limit, ?int $usage, int $priority = 1, array $extra = []): GoogleConnection
    {
        return GoogleConnection::query()->create([
            'user_id' => $this->user->id,
            'service' => GoogleService::Drive,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'google_account_email' => "drive{$priority}@example.test",
            'priority' => $priority,
            'storage_limit' => $limit,
            'storage_usage' => $usage,
            'storage_checked_at' => now(),
            ...$extra,
        ]);
    }

    private function pool(): DriveStoragePool
    {
        return app(DriveStoragePool::class);
    }

    // ── Totals ──────────────────────────────────────────────────────────────

    #[Test]
    public function space_adds_up_across_accounts(): void
    {
        // 15 GiB with 5 used, and 15 GiB with 10 used: 15 free, less 1 GiB of
        // reserve per account.
        $this->account(15 * self::GIB, 5 * self::GIB, priority: 1);
        $this->account(15 * self::GIB, 10 * self::GIB, priority: 2);

        $totals = $this->pool()->summary($this->user)['totals'];

        $this->assertSame(2, $totals['accounts']);
        $this->assertSame(30 * self::GIB, $totals['limit']);
        $this->assertSame(15 * self::GIB, $totals['usage']);
        $this->assertSame(13 * self::GIB, $totals['usable']);
    }

    #[Test]
    public function the_pool_is_not_one_drive(): void
    {
        /*
         * The number a total on its own would get wrong, and the reason
         * largest_single_file exists. Three accounts with 4 GiB usable each
         * report 12 GiB available and cannot take a 5 GiB file: a backup
         * lives in one account and cannot be split.
         */
        foreach ([1, 2, 3] as $priority) {
            $this->account(15 * self::GIB, 10 * self::GIB, priority: $priority);
        }

        $totals = $this->pool()->summary($this->user)['totals'];

        $this->assertSame(12 * self::GIB, $totals['usable']);
        $this->assertSame(4 * self::GIB, $totals['largest_single_file']);
        $this->assertNull($this->pool()->chooseFor($this->user, 5 * self::GIB));
    }

    #[Test]
    public function an_unmeasured_account_is_left_out_rather_than_assumed_empty(): void
    {
        // Counting an unread account as empty would promise space nobody has
        // established; counting it as full would hide space that is there.
        // It is excluded, and the count says so.
        $this->account(15 * self::GIB, 5 * self::GIB, priority: 1);
        $this->account(null, null, priority: 2, extra: ['storage_checked_at' => null]);

        $totals = $this->pool()->summary($this->user)['totals'];

        $this->assertSame(2, $totals['accounts']);
        $this->assertSame(1, $totals['measured_accounts']);
        $this->assertSame(9 * self::GIB, $totals['usable']);
    }

    #[Test]
    public function an_unlimited_account_is_not_a_full_one(): void
    {
        // Google reports no limit at all for some accounts. Read as zero, the
        // account would look full and every backup would be refused.
        $this->account(null, 2 * self::GIB, priority: 1);

        $summary = $this->pool()->summary($this->user);

        $this->assertTrue($summary['accounts'][0]['unlimited']);
        $this->assertTrue($summary['totals']['includes_unlimited']);
        $this->assertNull($summary['totals']['usable']);
        $this->assertNotNull($this->pool()->chooseFor($this->user, 500 * 1024 * 1024));
    }

    // ── Choosing an account ─────────────────────────────────────────────────

    #[Test]
    public function backups_fill_accounts_in_order(): void
    {
        $first = $this->account(15 * self::GIB, 5 * self::GIB, priority: 1);
        $this->account(15 * self::GIB, 0, priority: 2);

        // The roomier account is second, and still not chosen: filling in a
        // fixed order keeps a course contiguous in one Drive instead of
        // scattering it.
        $this->assertSame(
            $first->id,
            $this->pool()->chooseFor($this->user, self::GIB)?->id,
        );
    }

    #[Test]
    public function a_full_account_spills_to_the_next(): void
    {
        $this->account(15 * self::GIB, 15 * self::GIB, priority: 1);
        $spare = $this->account(15 * self::GIB, 2 * self::GIB, priority: 2);

        $this->assertSame(
            $spare->id,
            $this->pool()->chooseFor($this->user, 2 * self::GIB)?->id,
        );
    }

    #[Test]
    public function the_reserve_is_not_available_to_backups(): void
    {
        /*
         * Keje is a guest in an account that also holds somebody's mail.
         * 2 GiB free with a 1 GiB reserve takes a 1 GiB file and not a
         * 1.5 GiB one, so filling a Drive to the last byte never stops its
         * owner receiving email.
         */
        $account = $this->account(15 * self::GIB, 13 * self::GIB, priority: 1);

        $this->assertTrue($this->pool()->canAccept($account, self::GIB));
        $this->assertFalse($this->pool()->canAccept($account, (int) (1.5 * self::GIB)));
    }

    #[Test]
    public function an_unmeasured_account_is_never_chosen(): void
    {
        // Pessimistic on purpose: the optimistic reading discovers the account
        // is full part-way through several hundred megabytes.
        $account = $this->account(null, null, priority: 1, extra: ['storage_checked_at' => null]);

        $this->assertFalse($this->pool()->canAccept($account, 1024));
        $this->assertNull($this->pool()->chooseFor($this->user, 1024));
    }

    #[Test]
    public function nothing_is_chosen_when_no_account_is_connected(): void
    {
        $this->assertNull($this->pool()->chooseFor($this->user, 1024));
    }

    // ── What is still to back up ────────────────────────────────────────────

    #[Test]
    public function the_shortfall_is_named_rather_than_implied(): void
    {
        $this->account(15 * self::GIB, 13 * self::GIB, priority: 1);

        // 3 GiB of renders against 1 GiB usable.
        foreach ([self::GIB, self::GIB, self::GIB] as $index => $size) {
            ContentProject::factory()->create([
                'user_id' => $this->user->id,
                'output_path' => "content/p{$index}/renders/output.mp4",
                'output_size' => $size,
                'drive_status' => DriveStatus::Pending,
            ]);
        }

        $pending = $this->pool()->summary($this->user)['pending'];

        $this->assertSame(3, $pending['projects']);
        $this->assertFalse($pending['fits']);
        $this->assertSame(2 * self::GIB, $pending['shortfall']);
    }

    #[Test]
    public function a_project_already_backed_up_is_not_counted_again(): void
    {
        $this->account(15 * self::GIB, 0, priority: 1);

        ContentProject::factory()->create([
            'user_id' => $this->user->id,
            'output_path' => 'content/done/renders/output.mp4',
            'output_size' => self::GIB,
            'drive_status' => DriveStatus::Uploaded,
        ]);

        $pending = $this->pool()->summary($this->user)['pending'];

        $this->assertSame(0, $pending['projects']);
        $this->assertSame(0, $pending['bytes']);
    }

    #[Test]
    public function everything_fitting_in_total_is_not_the_same_as_fitting(): void
    {
        /*
         * The failure a total hides, and the one worth a separate warning.
         * Two accounts with 4 GiB usable each will take 6 GiB of small files
         * and will not take one 6 GiB file — so `fits` says yes and the
         * upload would still fail.
         */
        $this->account(15 * self::GIB, 10 * self::GIB, priority: 1);
        $this->account(15 * self::GIB, 10 * self::GIB, priority: 2);

        ContentProject::factory()->create([
            'user_id' => $this->user->id,
            'output_path' => 'content/big/renders/output.mp4',
            'output_size' => 6 * self::GIB,
            'drive_status' => DriveStatus::Pending,
        ]);

        $pending = $this->pool()->summary($this->user)['pending'];

        $this->assertTrue($pending['fits']);
        $this->assertFalse($pending['largest_project_fits']);
    }

    // ── The endpoint ────────────────────────────────────────────────────────

    #[Test]
    public function the_accounts_endpoint_reads_rather_than_measures(): void
    {
        $this->account(15 * self::GIB, 5 * self::GIB, priority: 1);

        $this->getJson('/api/v1/integrations/drive/accounts')
            ->assertOk()
            ->assertJsonPath('data.totals.accounts', 1)
            ->assertJsonPath('data.totals.usable', 9 * self::GIB)
            ->assertJsonPath('data.accounts.0.measured', true);
    }

    #[Test]
    public function no_google_token_is_ever_serialised_into_the_pool(): void
    {
        $this->account(15 * self::GIB, 5 * self::GIB, priority: 1);

        $body = $this->getJson('/api/v1/integrations/drive/accounts')->assertOk()->content();

        foreach (['access_token', 'refresh_token', 'access', 'refresh'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    #[Test]
    public function the_accounts_endpoint_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/integrations/drive/accounts')->assertStatus(401);
    }

    #[Test]
    public function an_account_can_be_named_and_moved_in_the_fill_order(): void
    {
        $account = $this->account(15 * self::GIB, 0, priority: 2);

        $this->patchJson("/api/v1/integrations/drive/accounts/{$account->uuid}", [
            'label' => 'Archive 2024',
            'priority' => 1,
        ])->assertOk()->assertJsonPath('data.accounts.0.label', 'Archive 2024');

        $account->refresh();
        $this->assertSame('Archive 2024', $account->account_label);
        $this->assertSame(1, $account->priority);
    }

    #[Test]
    public function another_users_drive_account_is_not_reachable(): void
    {
        $stranger = User::factory()->create();
        $theirs = GoogleConnection::query()->create([
            'user_id' => $stranger->id,
            'service' => GoogleService::Drive,
            'refresh_token' => 'refresh',
            'google_account_email' => 'stranger@example.test',
            'priority' => 1,
        ]);

        $this->patchJson("/api/v1/integrations/drive/accounts/{$theirs->uuid}", ['label' => 'mine now'])
            ->assertStatus(404);

        $this->assertNull($theirs->refresh()->account_label);
    }

    #[Test]
    public function a_youtube_connection_is_not_a_drive_account(): void
    {
        /*
         * Without the service check this would reach for the Drive API with a
         * token holding no Drive scope, and fail in a way that reads like a
         * broken integration rather than a bad request.
         */
        $youtube = GoogleConnection::query()->create([
            'user_id' => $this->user->id,
            'service' => GoogleService::YouTube,
            'refresh_token' => 'refresh',
        ]);

        $this->patchJson("/api/v1/integrations/drive/accounts/{$youtube->uuid}", ['label' => 'x'])
            ->assertStatus(404);
    }
}
