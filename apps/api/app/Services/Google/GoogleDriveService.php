<?php

namespace App\Services\Google;

use App\Models\User;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RuntimeException;

/**
 * Backs the finished MP4 up to the user's Drive.
 *
 * Uses a resumable, chunked upload: a rendered lecture can be hundreds of
 * megabytes and must never be read into PHP memory in one piece.
 *
 * Scope is drive.file, so this can only ever see files Keje created.
 */
class GoogleDriveService
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /**
     * Upload a file and return the stored Drive identifiers.
     *
     * @param  callable(float):void|null  $onProgress  receives 0..1
     * @return array{id:string, name:string, web_view_link:?string}
     */
    public function upload(
        User $user,
        string $absolutePath,
        string $filename,
        ?callable $onProgress = null,
    ): array {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The rendered video is no longer available to upload.');
        }

        $client = $this->clients->forUser($user);
        $drive = new Drive($client);

        $metadata = new DriveFile([
            'name' => $filename,
            'parents' => [$this->resolveFolderId($drive)],
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
    private function resolveFolderId(Drive $drive): string
    {
        $configured = config('services.drive.folder_id');

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
