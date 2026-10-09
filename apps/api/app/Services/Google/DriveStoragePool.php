<?php

namespace App\Services\Google;

use App\Enums\DriveStatus;
use App\Models\GoogleConnection;
use App\Models\User;

/**
 * Every connected Drive account, treated as one pool of space.
 *
 * Pure arithmetic over the quota figures DriveQuotaSync stored, so the
 * interesting part — what several accounts add up to, and whether a course
 * will fit in them — is testable without touching Google.
 *
 * ── What "available" honestly means ─────────────────────────────────────
 *
 * Three things keep this a snapshot rather than a promise, and the UI says so
 * rather than quietly rounding them away:
 *
 *  1. A Google account's quota is shared with Gmail and Photos. Free space
 *     can shrink with no involvement from Keje at all.
 *  2. The pool is not one drive. Twelve gigabytes free across three accounts
 *     will not take an eleven-gigabyte file, and nothing here pretends
 *     otherwise — see `largestSingleFile`.
 *  3. A file cannot be moved between accounts after upload. Each account owns
 *     what it stores, so spillover is decided once, when a backup is made,
 *     and cannot be rebalanced later without downloading and re-uploading.
 */
class DriveStoragePool
{
    /**
     * The whole picture for one user.
     *
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     pending: array<string, mixed>
     * }
     */
    public function summary(User $user): array
    {
        $accounts = $user->driveAccounts();

        $rows = $accounts->map(fn (GoogleConnection $c): array => $this->describe($c))->all();

        return [
            'accounts' => $rows,
            'totals' => $this->totals($accounts),
            'pending' => $this->pending($user, $accounts),
        ];
    }

    /**
     * Pick the account a file of this size should go to.
     *
     * Highest priority first, skipping any account that cannot take the file
     * with the reserve still intact. Filling in a fixed order rather than
     * spreading across accounts is deliberate: a course ends up contiguous in
     * one Drive instead of scattered, which matters when somebody goes
     * looking for it by hand.
     *
     * Null means nothing can take it, which the caller reports as a shortfall
     * rather than a failure — the fix is a new account, not a retry.
     */
    public function chooseFor(User $user, int $bytes): ?GoogleConnection
    {
        foreach ($user->driveAccounts() as $account) {
            if ($this->canAccept($account, $bytes)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Whether one account has room for a file of this size.
     *
     * An account whose quota has never been read returns false, not true. The
     * optimistic reading would route a backup to an account that may be full
     * and discover it part-way through a multi-hundred-megabyte upload; the
     * pessimistic one costs a quota read, which the upload path does first.
     */
    public function canAccept(GoogleConnection $account, int $bytes): bool
    {
        if ($account->hasUnlimitedStorage()) {
            return true;
        }

        $free = $account->freeBytes();

        if ($free === null) {
            return false;
        }

        return $free - $this->reserveBytes() >= $bytes;
    }

    /**
     * Headroom left untouched in every account.
     *
     * Keje is a guest in an account that also holds somebody's mail. Filling
     * a Drive to the last byte breaks Gmail, and the person would rightly
     * blame the thing that did the filling.
     */
    public function reserveBytes(): int
    {
        return max(0, (int) config('services.drive.reserve_bytes'));
    }

    /** @return array<string, mixed> */
    private function describe(GoogleConnection $account): array
    {
        $free = $account->freeBytes();
        $unlimited = $account->hasUnlimitedStorage();

        return [
            'id' => (string) $account->uuid,
            'email' => $account->google_account_email,
            'name' => $account->account_name,
            'label' => $account->account_label,
            'display_name' => $account->displayName(),
            'priority' => (int) $account->priority,
            'limit' => $account->storage_limit,
            'usage' => $account->storage_usage,
            'free' => $free,
            'unlimited' => $unlimited,
            // Usable rather than free: the reserve is not available to Keje,
            // so showing free space would overstate what a backup can have.
            'usable' => $unlimited ? null : ($free === null ? null : max(0, $free - $this->reserveBytes())),
            'percent_used' => $account->storage_limit > 0 && $account->storage_usage !== null
                ? round($account->storage_usage / $account->storage_limit * 100, 1)
                : null,
            'checked_at' => $account->storage_checked_at?->toIso8601String(),
            // Nothing has asked Google yet, so every figure above is unknown
            // rather than zero.
            'measured' => $account->storage_checked_at !== null,
            'healthy' => ! in_array(
                (string) $account->health_status,
                GoogleOAuthClassifier::brokenStatuses(),
                true,
            ),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GoogleConnection>  $accounts
     * @return array<string, mixed>
     */
    private function totals($accounts): array
    {
        $measured = $accounts->filter(fn (GoogleConnection $c): bool => $c->storage_checked_at !== null);
        $unlimited = $measured->contains(fn (GoogleConnection $c): bool => $c->hasUnlimitedStorage());

        $limit = 0;
        $usage = 0;
        $usable = 0;

        foreach ($measured as $account) {
            if ($account->hasUnlimitedStorage()) {
                continue;
            }

            $limit += (int) $account->storage_limit;
            $usage += (int) $account->storage_usage;
            $usable += max(0, (int) $account->freeBytes() - $this->reserveBytes());
        }

        return [
            'accounts' => $accounts->count(),
            'measured_accounts' => $measured->count(),
            // True when any account is unlimited, in which case the totals
            // below describe only the metered ones and the pool has no
            // meaningful ceiling.
            'includes_unlimited' => $unlimited,
            'limit' => $unlimited ? null : $limit,
            'usage' => $usage,
            'usable' => $unlimited ? null : $usable,
            /*
             * The biggest single file the pool could take, which is the
             * largest usable space in any one account — not the sum. Twelve
             * gigabytes spread over three accounts cannot hold an eleven
             * gigabyte file, and a total that implied it could would be a lie
             * discovered at upload time.
             */
            'largest_single_file' => $unlimited ? null : $measured
                ->reject(fn (GoogleConnection $c): bool => $c->hasUnlimitedStorage())
                ->map(fn (GoogleConnection $c): int => max(0, (int) $c->freeBytes() - $this->reserveBytes()))
                ->max() ?? 0,
        ];
    }

    /**
     * What is still waiting to be backed up, and whether it fits.
     *
     * Counts rendered projects whose backup has not succeeded. The comparison
     * is per-file against the largest account rather than bulk against the
     * total, because that is how the uploads will actually happen.
     *
     * @param  \Illuminate\Support\Collection<int, GoogleConnection>  $accounts
     * @return array<string, mixed>
     */
    private function pending(User $user, $accounts): array
    {
        $projects = $user->contentProjects()
            ->whereNotNull('output_path')
            ->where('drive_status', '!=', DriveStatus::Uploaded->value)
            ->get(['id', 'output_size']);

        $bytes = (int) $projects->sum('output_size');
        $largest = (int) ($projects->max('output_size') ?? 0);

        $totals = $this->totals($accounts);
        $usable = $totals['usable'];
        $biggestSlot = $totals['largest_single_file'];

        return [
            'projects' => $projects->count(),
            'bytes' => $bytes,
            'largest_project_bytes' => $largest,
            // Null while nothing is measured or an account is unlimited:
            // unknowable and limitless are both "not a shortfall", and
            // neither should render as a warning.
            'fits' => $usable === null ? null : $bytes <= $usable,
            'shortfall' => $usable === null ? null : max(0, $bytes - $usable),
            /*
             * The failure that a total hides. Every remaining file could fit
             * in the pool while the biggest one fits in no single account,
             * and that upload fails however much space the total reports.
             */
            'largest_project_fits' => $biggestSlot === null ? null : $largest <= $biggestSlot,
        ];
    }
}
