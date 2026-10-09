<?php

namespace App\Services\Google;

use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RuntimeException;
use Throwable;

/**
 * Read, rename and remove the backups Keje put in Drive.
 *
 * Until now a backup was write-only: Keje uploaded it and could list it, and
 * that was the whole relationship. A wrongly named file stayed wrongly named,
 * and a project re-rendered three times left three copies of a lecture
 * consuming quota with nothing able to clear them.
 *
 * ── Every operation is authorised against the caller's own connections ──
 *
 * A Drive file id arrives from the browser, and an id from a request is never
 * trusted as a capability — the same rule that stops a path parameter naming
 * any file on the server. Two things bound it here:
 *
 *  1. The token used is always one of the caller's own stored grants, so an
 *     id from somebody else's Drive resolves to nothing.
 *  2. `drive.file` means a token can only see files Keje itself created, so
 *     even a correct id for an unrelated file in the same account is
 *     invisible.
 *
 * On top of that, mutations verify the file sits inside Keje's backup folder
 * before touching it. Without that check a valid id for something else Keje
 * created in that account — a thumbnail, a future artefact — would be
 * renameable through the backups endpoint.
 */
class DriveBackupManager
{
    private const FILE_FIELDS = 'id,name,mimeType,size,createdTime,modifiedTime,webViewLink,parents,trashed';

    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly DriveCatalogService $catalog,
    ) {}

    /**
     * Every backup across every connected account, newest first.
     *
     * Paged per account rather than globally: Drive paginates per request and
     * there is no cross-account cursor to hand out. Each account's slice
     * carries its own token, so a caller can keep reading the one that has
     * more.
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function listAll(User $user, int $perAccount = 20): array
    {
        $accounts = [];

        foreach ($user->driveAccounts() as $account) {
            $accounts[] = $this->listForAccount($account, $perAccount);
        }

        return ['accounts' => $accounts];
    }

    /** @return array<string, mixed> */
    public function listForAccount(GoogleConnection $account, int $perAccount = 20, ?string $pageToken = null): array
    {
        $row = [
            'account' => [
                'id' => (string) $account->uuid,
                'email' => $account->google_account_email,
                'display_name' => $account->displayName(),
            ],
            'files' => [],
            'next_page_token' => null,
            'error' => null,
        ];

        try {
            $folder = $this->folderFor($account);

            if ($folder === null) {
                // Nothing has been backed up to this account yet, so Keje has
                // not created its folder. Not an error, and not an empty
                // Drive — just nothing of ours in it.
                return $row;
            }

            $page = $this->catalog->backupsForConnection($account, $folder['id'], $pageToken, $perAccount);

            $row['files'] = $page['data'];
            $row['next_page_token'] = $page['next_page_token'];
        } catch (Throwable $e) {
            report($e);

            // One account failing must not blank the others. The page shows
            // what it could read and says which account it could not.
            $row['error'] = 'Could not read this account.';
        }

        return $row;
    }

    /**
     * Rename a backup.
     *
     * Renames the Drive file and, when the file is a project's recorded
     * backup, the stored name with it. Letting those drift would leave the
     * project page naming a file that no longer exists under that name.
     *
     * @return array<string, mixed>
     */
    public function rename(User $user, GoogleConnection $account, string $fileId, string $name): array
    {
        $this->assertBackup($account, $fileId);

        $updated = $this->driveFor($account)->files->update(
            $fileId,
            new DriveFile(['name' => $name]),
            ['fields' => self::FILE_FIELDS],
        );

        ContentProject::query()
            ->where('user_id', $user->id)
            ->where('drive_file_id', $fileId)
            ->update(['drive_file_name' => (string) $updated->getName()]);

        return $this->normalise($updated);
    }

    /**
     * Move a backup to the Drive trash.
     *
     * Trashed, not deleted outright. Google keeps a trashed file for thirty
     * days and its owner can restore it from Drive's own interface, which is
     * the right default for the only remaining copy of a lecture — the local
     * render is pruned once the backup succeeds, so a permanent delete here
     * could be the last copy going. It stops counting against quota once
     * Drive empties the trash, and emptying it early is something the account
     * owner does, not something Keje does on their behalf.
     */
    public function trash(User $user, GoogleConnection $account, string $fileId): void
    {
        $this->assertBackup($account, $fileId);

        $this->driveFor($account)->files->update(
            $fileId,
            new DriveFile(['trashed' => true]),
            ['fields' => 'id,trashed'],
        );

        $this->forgetProjectBackup($user, $fileId);
    }

    /**
     * Remove the backup belonging to one project, and forget it.
     *
     * The operation that keeps the two sides in step. Trashing a file through
     * the file list leaves a project still claiming a Drive copy; doing it
     * from the project clears drive_status, the id, the name and the link
     * together, so the project honestly reads as not backed up and can be
     * backed up again.
     */
    public function removeForProject(ContentProject $project): void
    {
        if (blank($project->drive_file_id)) {
            throw new RuntimeException('This project has no Drive backup to remove.');
        }

        $account = $project->driveConnection;

        if ($account === null) {
            // The account was disconnected. The file is still in whoever's
            // Drive it was, and Keje has no token to reach it — so the honest
            // move is to forget our record and say so, rather than claim a
            // deletion that did not happen.
            throw new RuntimeException(
                'The Google account holding this backup is no longer connected, '
                .'so the file cannot be removed from here. Reconnect that account, '
                .'or delete the file from Google Drive directly.',
            );
        }

        $this->trash($project->user, $account, (string) $project->drive_file_id);
    }

    /**
     * Refuse to touch anything that is not one of Keje's backups.
     *
     * drive.file already limits a token to files this app created, which
     * bounds the damage considerably. This narrows it again to the backup
     * folder, so the backups endpoints cannot be used as a general handle on
     * everything Keje has ever created in that Drive.
     */
    private function assertBackup(GoogleConnection $account, string $fileId): void
    {
        $folder = $this->folderFor($account);

        if ($folder === null) {
            throw new RuntimeException('This account has no Keje backup folder.');
        }

        try {
            $file = $this->driveFor($account)->files->get($fileId, ['fields' => 'id,parents,trashed']);
        } catch (Throwable) {
            // Invisible under drive.file, or not in this account at all. The
            // two are indistinguishable from here and need the same answer.
            throw new RuntimeException('That backup could not be found in this account.');
        }

        if (! in_array($folder['id'], (array) $file->getParents(), true)) {
            throw new RuntimeException('That file is not one of this account\'s Keje backups.');
        }
    }

    private function forgetProjectBackup(User $user, string $fileId): void
    {
        ContentProject::query()
            ->where('user_id', $user->id)
            ->where('drive_file_id', $fileId)
            ->update([
                'drive_status' => \App\Enums\DriveStatus::Pending->value,
                'drive_file_id' => null,
                'drive_file_name' => null,
                'drive_web_view_link' => null,
                'drive_uploaded_at' => null,
                'drive_connection_id' => null,
                'drive_error' => null,
            ]);
    }

    /** @return array<string, mixed>|null */
    private function folderFor(GoogleConnection $account): ?array
    {
        return $this->catalog->backupFolderForConnection($account);
    }

    /** @return array<string, mixed> */
    private function normalise(DriveFile $file): array
    {
        return [
            'id' => (string) $file->getId(),
            'name' => $file->getName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() === null ? null : (int) $file->getSize(),
            'created_at' => $file->getCreatedTime(),
            'modified_at' => $file->getModifiedTime(),
            'web_view_link' => $file->getWebViewLink(),
        ];
    }

    /**
     * The Drive API for one account.
     *
     * Protected rather than private so the tests can stand in for the one
     * part that talks to Google while the authorisation logic above — which
     * is the part worth testing — runs for real.
     */
    protected function driveFor(GoogleConnection $account): Drive
    {
        return new Drive($this->clients->forConnection($account));
    }
}
