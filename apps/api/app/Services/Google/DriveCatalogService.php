<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\GoogleConnection;
use App\Models\User;
use Google\Service\Drive;
use Throwable;

/**
 * Reads what Keje can see of the connected Drive.
 *
 * The authorization is `drive.file` and stays that way. That scope grants
 * access only to files this application created or the user explicitly opened
 * to it — not the account's Drive. Everything here is therefore scoped to
 * Keje's own backup folder, and the UI says "Keje-accessible" rather than
 * implying it lists a Drive.
 *
 * Broadening to `drive`, `drive.readonly` or `drive.metadata.readonly` would
 * make a fuller file browser possible and is deliberately not done: reading a
 * user's entire Drive to render a backup list is not a trade worth making.
 */
class DriveCatalogService
{
    /** Requested explicitly — an unmasked about.get returns far more than this. */
    private const ABOUT_FIELDS = 'user(displayName,emailAddress,photoLink),'
        .'storageQuota(limit,usage,usageInDrive,usageInDriveTrash)';

    private const FILE_FIELDS = 'id,name,mimeType,size,createdTime,modifiedTime,webViewLink';

    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /**
     * Account identity and storage quota.
     *
     * @return array<string, mixed>
     */
    public function about(User $user): array
    {
        return $this->aboutForConnection($this->connection($user));
    }

    /**
     * The same, for one named account.
     *
     * Every read is addressable per connection now that a user can hold
     * several Drive accounts. The user-shaped methods above stay as the
     * "does Drive work at all" form and delegate here, so there is one
     * implementation rather than two that drift.
     *
     * @return array<string, mixed>
     */
    public function aboutForConnection(GoogleConnection $connection): array
    {
        $about = $this->apiFor($connection)->about->get(['fields' => self::ABOUT_FIELDS]);

        $account = $about->getUser();
        $quota = $about->getStorageQuota();

        // An unlimited account reports no limit at all rather than a number.
        $limit = $quota?->getLimit() === null ? null : (int) $quota->getLimit();
        $usage = $quota?->getUsage() === null ? null : (int) $quota->getUsage();

        return [
            'account' => [
                'name' => $account?->getDisplayName(),
                'email' => $account?->getEmailAddress(),
                'photo_url' => $account?->getPhotoLink(),
            ],
            'storage' => [
                'limit' => $limit,
                'usage' => $usage,
                'usage_in_drive' => $quota?->getUsageInDrive() === null ? null : (int) $quota->getUsageInDrive(),
                'usage_in_trash' => $quota?->getUsageInDriveTrash() === null ? null : (int) $quota->getUsageInDriveTrash(),
                'unlimited' => $limit === null,
                'percent_used' => $limit > 0 && $usage !== null
                    ? round($usage / $limit * 100, 1)
                    : null,
            ],
        ];
    }

    /**
     * The folder Keje backs up into, as far as it can see it.
     *
     * Returns null when no folder has been created or the configured one is
     * no longer reachable — a missing folder is a thing to report, never a
     * reason to treat the connection as broken.
     *
     * @return array<string, mixed>|null
     */
    public function backupFolder(User $user): ?array
    {
        return $this->backupFolderForConnection($this->connection($user));
    }

    /** @return array<string, mixed>|null */
    public function backupFolderForConnection(GoogleConnection $connection): ?array
    {
        $drive = $this->apiFor($connection);

        /*
         * GOOGLE_DRIVE_FOLDER_ID names a folder in exactly one account, so it
         * is honoured only for the first account in the fill order. Applying
         * it to every account would have each of them look up an id that
         * cannot exist in their Drive, fail, and fall back to the name —
         * after a wasted round trip apiece.
         */
        $configured = $connection->priority <= 1
            ? (string) config('services.drive.folder_id')
            : '';

        try {
            if (filled($configured)) {
                $folder = $drive->files->get($configured, [
                    'fields' => self::FILE_FIELDS,
                ]);

                return $this->normaliseFolder($folder, configured: true);
            }
        } catch (Throwable) {
            // Configured but gone, or never shared with this app. Reported as
            // unavailable by the caller; Drive itself is still connected.
            return null;
        }

        // No id configured: Keje creates a folder by name, and drive.file means
        // the lookup only ever sees the one it created itself.
        $name = (string) config('services.drive.folder_name');
        $escaped = str_replace("'", "\\'", $name);

        $matches = $drive->files->listFiles([
            'q' => "mimeType='application/vnd.google-apps.folder' and name='{$escaped}' and trashed=false",
            'fields' => 'files('.self::FILE_FIELDS.')',
            'pageSize' => 1,
        ]);

        $folder = $matches->getFiles()[0] ?? null;

        return $folder === null ? null : $this->normaliseFolder($folder, configured: false);
    }

    /**
     * Files inside the backup folder, one page at a time.
     *
     * @return array{data: list<array<string, mixed>>, next_page_token: ?string}
     */
    public function backups(User $user, string $folderId, ?string $pageToken = null, int $limit = 20): array
    {
        return $this->backupsForConnection($this->connection($user), $folderId, $pageToken, $limit);
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_page_token: ?string}
     */
    public function backupsForConnection(
        GoogleConnection $connection,
        string $folderId,
        ?string $pageToken = null,
        int $limit = 20,
    ): array {
        $params = [
            'q' => "'{$folderId}' in parents and trashed = false",
            'fields' => 'nextPageToken,files('.self::FILE_FIELDS.')',
            'orderBy' => 'createdTime desc',
            'pageSize' => max(1, min($limit, 100)),
        ];

        if (filled($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->apiFor($connection)->files->listFiles($params);

        $files = [];

        foreach ($response->getFiles() as $file) {
            $files[] = [
                'id' => (string) $file->getId(),
                'name' => $file->getName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize() === null ? null : (int) $file->getSize(),
                'created_at' => $file->getCreatedTime(),
                'modified_at' => $file->getModifiedTime(),
                'web_view_link' => $file->getWebViewLink(),
            ];
        }

        return [
            'data' => $files,
            'next_page_token' => $response->getNextPageToken(),
        ];
    }

    /** @return array<string, mixed> */
    private function normaliseFolder(mixed $folder, bool $configured): array
    {
        return [
            'id' => (string) $folder->getId(),
            'name' => $folder->getName(),
            'web_view_link' => $folder->getWebViewLink(),
            'created_at' => $folder->getCreatedTime(),
            'modified_at' => $folder->getModifiedTime(),
            'configured' => $configured,
        ];
    }

    private function apiFor(GoogleConnection $connection): Drive
    {
        return new Drive($this->clients->forConnection($connection));
    }

    /**
     * The account the user-shaped methods mean.
     *
     * The highest-priority Drive account, which is the one "is Drive working"
     * is asking about. Anything addressing a particular file takes a
     * connection instead — see DriveBackupManager.
     *
     * @throws GoogleNotConnectedException
     */
    private function connection(User $user): GoogleConnection
    {
        $connection = $user->googleConnectionFor(GoogleService::Drive);

        if ($connection === null) {
            throw new GoogleNotConnectedException(
                'Google Drive is not connected. Connect it from Settings → Integrations.',
            );
        }

        return $connection;
    }
}
