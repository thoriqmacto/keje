<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use Google\Service\Drive;
use Illuminate\Support\Carbon;

/**
 * Asks Google what one Drive account's quota is, and remembers the answer.
 *
 * Split from DriveStoragePool for the same reason GoogleApiReachability is
 * split from the health check: this is the only part that talks to Google, so
 * isolating it is what lets the arithmetic be tested without a network. The
 * interesting question is never "what did about.get say" — it is what the sum
 * of several accounts means, and that should not need the internet.
 *
 * ── What the numbers are, and are not ───────────────────────────────────
 *
 * `storageQuota` describes the whole Google account: Drive, Gmail and Photos
 * together. Keje's own backups are a subset of `usage`, usually a small one,
 * and under `drive.file` Keje cannot even see the rest. So the usage here and
 * the file list on the Drive page answer different questions and will not add
 * up. That is correct, and the UI labels both rather than pretending one
 * explains the other.
 *
 * It also means free space can shrink without Keje doing anything at all —
 * a large mailbox attachment is enough. Every figure derived from this is a
 * snapshot, never a reservation.
 */
class DriveQuotaSync
{
    /** Only what is needed. An unmasked about.get returns a great deal more. */
    private const FIELDS = 'user(displayName,emailAddress),'
        .'storageQuota(limit,usage,usageInDrive,usageInDriveTrash)';

    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /**
     * Read the quota for one account and store it on the connection.
     *
     * Also refreshes the account's identity, because an email address is how
     * every other part of this feature tells one account from another and a
     * display name can change.
     */
    public function refresh(GoogleConnection $connection): void
    {
        $about = $this->read($connection);

        $quota = $about->getStorageQuota();
        $account = $about->getUser();

        $connection->forceFill([
            // Null for an account Google reports as unlimited. Deliberately
            // not coerced to 0, which would read as "full".
            'storage_limit' => $quota?->getLimit() === null ? null : (int) $quota->getLimit(),
            'storage_usage' => $quota?->getUsage() === null ? null : (int) $quota->getUsage(),
            'storage_checked_at' => Carbon::now(),
            'google_account_email' => $account?->getEmailAddress() ?: $connection->google_account_email,
            'account_name' => $account?->getDisplayName() ?: $connection->account_name,
        ])->save();
    }

    /**
     * Identify the account behind a grant, without storing anything.
     *
     * Used by the OAuth callback before it writes a row: connecting the same
     * account twice would produce two connections competing for one quota,
     * and the only way to notice is to ask Google who just consented.
     *
     * @return array{email:?string, name:?string}
     */
    public function identify(GoogleConnection $connection): array
    {
        $account = $this->read($connection)->getUser();

        return [
            'email' => $account?->getEmailAddress(),
            'name' => $account?->getDisplayName(),
        ];
    }

    private function read(GoogleConnection $connection): \Google\Service\Drive\About
    {
        $client = $this->clients->forConnection($connection);

        return (new Drive($client))->about->get(['fields' => self::FIELDS]);
    }
}
