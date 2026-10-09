<?php

namespace App\Services\Google;

use App\Exceptions\Google\DriveStorageExhaustedException;
use App\Models\GoogleConnection;
use App\Models\User;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RuntimeException;
use Throwable;

/**
 * Backs the finished MP4 up to the user's Drive.
 *
 * Uses a resumable, chunked upload: a rendered lecture can be hundreds of
 * megabytes and must never be read into PHP memory in one piece.
 *
 * Scope is drive.file, so this can only ever see files Keje created. It uses
 * the Drive OAuth client exclusively — a missing or broken YouTube
 * connection has no bearing on a backup.
 *
 * Which account a backup goes to is decided here too, by accountFor(). A
 * backup cannot be moved between accounts afterwards: each Google account
 * owns what it stores, so moving means downloading and re-uploading. That is
 * why the choice is made against a freshly read quota rather than a cached
 * one — it is not revisitable.
 */
class GoogleDriveService
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly DriveStoragePool $pool,
        private readonly DriveQuotaSync $quota,
    ) {}

    /**
     * Pick the account this file should go to, measuring first.
     *
     * The quota is re-read before choosing rather than trusted from the
     * hourly pass, because the number that matters is the one at the moment
     * of upload: the account is shared with Gmail and Photos, and discovering
     * it is full part-way through three hundred megabytes wastes the whole
     * transfer. One about.get per account is nothing next to that.
     *
     * A refresh that fails leaves the stored figure in place rather than
     * aborting. A stale quota is still a usable basis for choosing; no quota
     * at all is not.
     *
     * @throws DriveStorageExhaustedException when no account can take the file
     */
    public function accountFor(User $user, int $bytes): GoogleConnection
    {
        $accounts = $user->driveAccounts();

        foreach ($accounts as $account) {
            try {
                $this->quota->refresh($account);
            } catch (Throwable) {
                // Keep whatever was last measured; see above.
            }
        }

        $chosen = $this->pool->chooseFor($user->refresh(), $bytes);

        if ($chosen !== null) {
            return $chosen;
        }

        $largest = $user->driveAccounts()
            ->map(fn (GoogleConnection $a): int => max(0, (int) $a->freeBytes() - $this->pool->reserveBytes()))
            ->max() ?? 0;

        throw new DriveStorageExhaustedException(
            $accounts->isEmpty()
                ? 'No Google Drive account is connected, so there is nowhere to back this up.'
                : sprintf(
                    'This backup needs %s and the roomiest connected Drive account has %s free. '
                    .'Connect another Google Drive account, or free space in one of the %d already connected.',
                    $this->humanBytes($bytes),
                    $this->humanBytes((int) $largest),
                    $accounts->count(),
                ),
            requiredBytes: $bytes,
            largestFreeBytes: (int) $largest,
        );
    }

    /**
     * Bytes, for a sentence somebody reads rather than a column they sort.
     *
     * Not the web formatter: this string ends up in a stored error message
     * and an email, both of which are read where no JavaScript runs.
     */
    private function humanBytes(int $bytes): string
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

    /**
     * Upload a file and return the stored Drive identifiers.
     *
     * @param  callable(float):void|null  $onProgress  receives 0..1
     * @return array{id:string, name:string, web_view_link:?string}
     */
    public function upload(
        GoogleConnection $account,
        string $absolutePath,
        string $filename,
        ?callable $onProgress = null,
    ): array {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The rendered video is no longer available to upload.');
        }

        $client = $this->clients->forConnection($account);
        $drive = new Drive($client);

        $metadata = new DriveFile([
            'name' => $filename,
            'parents' => [$this->resolveFolderId($drive, $account)],
        ]);

        // Defer execution so the request becomes the resumable session opener
        // rather than a single upload.
        $client->setDefer(true);

        $request = $drive->files->create($metadata, [
            'fields' => 'id,name,webViewLink',
            'supportsAllDrives' => false,
        ]);

        $chunkSize = $this->chunkSize((int) config('services.drive.chunk_size'));
        $size = (int) filesize($absolutePath);

        $media = new MediaFileUpload($client, $request, 'video/mp4', '', true, $chunkSize);
        $media->setFileSize($size);

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            $client->setDefer(false);
            throw new RuntimeException('Could not open the rendered video for upload.');
        }

        try {
            $status = false;
            $uploaded = 0;

            while (! $status && ! feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === false) {
                    throw new RuntimeException('Failed while reading the rendered video.');
                }

                $status = $media->nextChunk($chunk);
                $uploaded += strlen($chunk);

                if ($onProgress !== null && $size > 0) {
                    $onProgress(min(1.0, $uploaded / $size));
                }
            }
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }

        if (! $status instanceof DriveFile) {
            throw new RuntimeException('Google Drive did not confirm the upload.');
        }

        return [
            'id' => (string) $status->getId(),
            'name' => (string) $status->getName(),
            'web_view_link' => $status->getWebViewLink(),
        ];
    }

    /**
     * The configured folder, or one created on demand by name.
     *
     * With drive.file scope the lookup only sees folders this app created, so
     * a folder of the same name made by hand in the Drive UI is invisible and
     * a new one is created instead.
     */
    private function resolveFolderId(Drive $drive, GoogleConnection $account): string
    {
        // A configured folder id exists in one account only, so it applies to
        // the first in the fill order. Every other account gets a folder of
        // its own, created by name.
        $configured = $account->priority <= 1
            ? config('services.drive.folder_id')
            : null;

        if (filled($configured)) {
            return (string) $configured;
        }

        $name = (string) config('services.drive.folder_name');
        $escaped = str_replace("'", "\\'", $name);

        $existing = $drive->files->listFiles([
            'q' => "mimeType='application/vnd.google-apps.folder' and name='{$escaped}' and trashed=false",
            'fields' => 'files(id,name)',
            'pageSize' => 1,
        ]);

        $folder = $existing->getFiles()[0] ?? null;

        if ($folder !== null) {
            return (string) $folder->getId();
        }

        $created = $drive->files->create(
            new DriveFile([
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
            ['fields' => 'id'],
        );

        return (string) $created->getId();
    }

    /** Google requires resumable chunks to be a multiple of 256 KiB. */
    private function chunkSize(int $requested): int
    {
        $unit = 256 * 1024;

        return max($unit, (int) (floor($requested / $unit) * $unit));
    }
}
