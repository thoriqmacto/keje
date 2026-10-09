<?php

namespace App\Console\Commands;

use App\Models\GoogleConnection;
use App\Services\Google\DriveQuotaSync;
use App\Services\Google\DriveStoragePool;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-read every Drive account's quota, on a schedule.
 *
 * The figures the Drive page and the upload chooser read are stored rather
 * than measured live, so something has to keep them current. This is it.
 *
 * It matters more than a cache refresh usually would, because the quota is
 * not Keje's to predict: it is shared with Gmail and Photos, so an account
 * can go from comfortable to nearly full without a single backup being made.
 * A stored figure from last week would route a lecture to an account that
 * filled up on Tuesday and fail part-way through three hundred megabytes.
 *
 * The upload path re-reads the quota itself before choosing, so this is not
 * what makes a backup land in the right place. What it buys is a Drive page
 * that is true when somebody opens it, and a warning that arrives before the
 * upload rather than during it.
 */
class DriveStorageCheckCommand extends Command
{
    protected $signature = 'drive:storage
        {--user= : Only this user id}';

    protected $description = 'Re-read the storage quota of every connected Google Drive account';

    public function handle(DriveQuotaSync $quota, DriveStoragePool $pool): int
    {
        $accounts = GoogleConnection::query()
            ->where('service', 'drive')
            ->when($this->option('user'), fn ($q) => $q->where('user_id', $this->option('user')))
            ->with('user')
            ->orderBy('user_id')
            ->orderBy('priority')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No Google Drive accounts to check.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $quota->refresh($account);
                $this->report($account->fresh(), $pool);
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('%-32s %s', $account->displayName(), $e->getMessage()));
            }
        }

        // Non-zero when something could not be read, so cron's own
        // mail-on-failure works as a second channel. A full account is not a
        // failure — it is a fact, and the page says so.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function report(GoogleConnection $account, DriveStoragePool $pool): void
    {
        if ($account->hasUnlimitedStorage()) {
            $this->info(sprintf('%-32s unlimited', $account->displayName()));

            return;
        }

        $usable = max(0, (int) $account->freeBytes() - $pool->reserveBytes());
        $line = sprintf(
            '%-32s %s usable of %s',
            $account->displayName(),
            $this->bytes($usable),
            $this->bytes((int) $account->storage_limit),
        );

        // Amber below the reserve: still working, but the next lecture-sized
        // file will go to another account, and if there is no other account
        // it will not go anywhere.
        $usable < $pool->reserveBytes() ? $this->warn($line) : $this->line($line);
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$unit];
    }
}
