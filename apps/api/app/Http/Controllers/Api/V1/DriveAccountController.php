<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RenameDriveBackupRequest;
use App\Http\Requests\Api\V1\UpdateDriveAccountRequest;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Services\Google\DriveBackupManager;
use App\Services\Google\DriveQuotaSync;
use App\Services\Google\DriveStoragePool;
use App\Services\Google\GoogleCatalogCache;
use App\Services\Google\GoogleErrorTranslator;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * The Drive accounts a user backs up to, and the files in them.
 *
 * One free Google account holds fifteen gigabytes shared with Gmail and
 * Photos, which makes it a ceiling on how much of a course Keje can keep
 * rather than a comfortable amount. So Drive is a pool of accounts here, with
 * a total, a fill order, and the ability to add one when another fills up.
 */
class DriveAccountController extends Controller
{
    public function __construct(
        private readonly DriveStoragePool $pool,
        private readonly DriveQuotaSync $quota,
        private readonly DriveBackupManager $backups,
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleCatalogCache $cache,
        private readonly GoogleErrorTranslator $errors,
    ) {}

    /**
     * Every connected account, the pooled total, and what is still to upload.
     *
     * A read of the stored quota figures, never a live measurement. The page
     * shows every account at once and putting a Google round trip per account
     * in front of that would make opening it wait on somebody else's network
     * — the same reason the connection-health endpoint reads rather than
     * probes. `checked_at` travels with each account so a stale figure can be
     * recognised instead of trusted.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pool->summary($request->user())]);
    }

    /**
     * Re-read every account's quota from Google now.
     *
     * For the moment after somebody has cleared space, or added an account,
     * and does not want to wait for the scheduled pass to be believed. One
     * account failing does not fail the request: its stored figure stays and
     * the response says which could not be read.
     */
    public function refresh(Request $request): JsonResponse
    {
        $failed = [];

        foreach ($request->user()->driveAccounts() as $account) {
            try {
                $this->quota->refresh($account);
            } catch (Throwable $e) {
                report($e);
                $failed[] = $account->displayName();
            }
        }

        $this->cache->flush($request->user(), GoogleService::Drive);

        return response()->json([
            'data' => $this->pool->summary($request->user()->refresh()),
            'message' => $failed === []
                ? 'Storage re-read from Google.'
                : 'Could not read: '.implode(', ', $failed).'.',
        ]);
    }

    /** Rename an account, or move it in the fill order. */
    public function update(UpdateDriveAccountRequest $request, GoogleConnection $connection): JsonResponse
    {
        $this->authorizeAccount($request, $connection);

        $changes = $request->validated();

        // The request says "label" because that is what it is on screen; the
        // column is account_label, beside account_name and account_key. Mapped
        // rather than renamed either side: "label" in an API payload is clear,
        // and a bare "label" column next to those three would not be.
        if (array_key_exists('label', $changes)) {
            $changes['account_label'] = $changes['label'] ?: null;
            unset($changes['label']);
        }

        $connection->forceFill($changes)->save();

        return response()->json([
            'data' => $this->pool->summary($request->user()->refresh()),
            'message' => 'Account updated.',
        ]);
    }

    /**
     * Disconnect one account, leaving the rest.
     *
     * Projects backed up here keep their Drive file id and lose the link to
     * the account, so the studio can say the backup exists somewhere Keje can
     * no longer reach rather than claiming it never happened.
     */
    public function destroy(Request $request, GoogleConnection $connection): JsonResponse
    {
        $this->authorizeAccount($request, $connection);

        $orphaned = ContentProject::query()
            ->where('drive_connection_id', $connection->id)
            ->count();

        $name = $connection->displayName();

        $this->oauth->disconnectAccount($connection);

        return response()->json([
            'data' => $this->pool->summary($request->user()->refresh()),
            'message' => $orphaned === 0
                ? "{$name} disconnected."
                : "{$name} disconnected. {$orphaned} ".
                    ($orphaned === 1 ? 'backup is' : 'backups are').
                    ' still in that Drive, and Keje can no longer manage them.',
        ]);
    }

    /** Every backup Keje can see, grouped by the account holding it. */
    public function backups(Request $request): JsonResponse
    {
        $perAccount = (int) $request->integer('per_account', 20);

        return response()->json([
            'data' => $this->backups->listAll($request->user(), max(1, min($perAccount, 100))),
        ]);
    }

    public function renameBackup(
        RenameDriveBackupRequest $request,
        GoogleConnection $connection,
        string $fileId,
    ): JsonResponse {
        $this->authorizeAccount($request, $connection);

        return $this->attempt(function () use ($request, $connection, $fileId) {
            $file = $this->backups->rename(
                $request->user(),
                $connection,
                $fileId,
                (string) $request->validated('name'),
            );

            return ['data' => $file, 'message' => 'Backup renamed.'];
        });
    }

    /**
     * Move a backup to the Drive trash.
     *
     * Trash rather than a permanent delete: the local render is pruned once a
     * backup succeeds, so this can be the last copy of a lecture, and Drive
     * keeps a trashed file recoverable for thirty days. Emptying the trash is
     * the account owner's decision, not Keje's.
     */
    public function destroyBackup(
        Request $request,
        GoogleConnection $connection,
        string $fileId,
    ): JsonResponse {
        $this->authorizeAccount($request, $connection);

        return $this->attempt(function () use ($request, $connection, $fileId) {
            $this->backups->trash($request->user(), $connection, $fileId);

            return ['message' => 'Backup moved to the Drive trash, where it can be restored for 30 days.'];
        });
    }

    /**
     * Remove the backup belonging to one project.
     *
     * The form that keeps both sides in step: trashing through the file list
     * leaves a project still claiming a Drive copy, and doing it from the
     * project clears the drive_* columns together so the project honestly
     * reads as not backed up and can be backed up again.
     */
    public function destroyProjectBackup(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        return $this->attempt(function () use ($project) {
            $this->backups->removeForProject($project);

            return [
                'message' => 'Backup moved to the Drive trash. This project is no longer backed up.',
                'data' => new \App\Http\Resources\Api\V1\ContentProjectResource(
                    $project->fresh(['topic', 'speaker']),
                ),
            ];
        });
    }

    /**
     * This account belongs to the caller, and is a Drive account.
     *
     * Both halves matter. The first is ordinary ownership. The second stops a
     * YouTube connection id being passed to a Drive endpoint, which would
     * reach for a Drive API with a token that holds no Drive scope and fail
     * in a way that reads like a broken integration rather than a bad
     * request.
     */
    private function authorizeAccount(Request $request, GoogleConnection $connection): void
    {
        abort_unless(
            $connection->user_id === $request->user()->id
                && $connection->service === GoogleService::Drive,
            404,
        );
    }

    /**
     * Run a Drive operation and turn its failures into something readable.
     *
     * RuntimeException is this code's own refusal — not found, not a backup,
     * no account — and is the user's to act on, so it answers 422. Anything
     * else came from Google and is translated, never echoed: a Drive error
     * body can quote request parameters back.
     *
     * @param  callable(): array<string, mixed>  $operation
     */
    private function attempt(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation());
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'drive_refused',
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $this->errors->translate($e, 'Google Drive refused that change.'),
                'error' => $this->errors->isExpiredGrant($e) ? 'reconnect_required' : 'google_error',
            ], 502);
        }
    }
}
