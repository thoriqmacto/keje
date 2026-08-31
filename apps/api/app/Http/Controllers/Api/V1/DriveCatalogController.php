<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleService;
use App\Http\Controllers\Controller;
use App\Services\Google\DriveCatalogService;
use App\Services\Google\GoogleCatalogCache;
use App\Services\Google\GoogleErrorTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * What Keje can see of the connected Drive under `drive.file`.
 *
 * That scope limits this to Keje's own backup folder and the files it created
 * there. It is not a view of the user's Drive and is not presented as one.
 */
class DriveCatalogController extends Controller
{
    public function __construct(
        private readonly DriveCatalogService $catalog,
        private readonly GoogleCatalogCache $cache,
        private readonly GoogleErrorTranslator $errors,
    ) {}

    /** Account identity, storage quota and the backup folder. */
    public function about(Request $request): JsonResponse
    {
        return $this->resource($request, 'Drive account', function () use ($request) {
            $user = $request->user();

            $about = $this->cache->remember(
                $user,
                GoogleService::Drive,
                'about',
                fn () => $this->catalog->about($user),
            );

            // A folder that has gone missing is reported, never treated as a
            // broken connection: Drive is still authorized and still working.
            $folder = $this->cache->remember(
                $user,
                GoogleService::Drive,
                'backup_folder',
                fn () => $this->catalog->backupFolder($user),
            );

            return [
                ...$about,
                'backup_folder' => $folder,
                'backup_folder_available' => $folder !== null,
            ];
        });
    }

    /** Files inside Keje's backup folder. */
    public function backups(Request $request): JsonResponse
    {
        $pageToken = (string) $request->query('page_token', '');

        return $this->resource($request, 'Drive backups', function () use ($request, $pageToken) {
            $user = $request->user();

            $folder = $this->cache->remember(
                $user,
                GoogleService::Drive,
                'backup_folder',
                fn () => $this->catalog->backupFolder($user),
            );

            if ($folder === null) {
                return ['data' => [], 'next_page_token' => null];
            }

            return $this->cache->remember(
                $user,
                GoogleService::Drive,
                'backups',
                fn () => $this->catalog->backups($user, $folder['id'], $pageToken ?: null),
                variant: $pageToken,
            );
        });
    }

    public function refresh(Request $request): JsonResponse
    {
        $this->cache->flush($request->user(), GoogleService::Drive);

        return $this->about($request);
    }

    /** @param  callable(): mixed  $resolve */
    private function resource(Request $request, string $name, callable $resolve): JsonResponse
    {
        $connection = $request->user()->googleConnectionFor(GoogleService::Drive);

        if ($connection === null || ! $connection->isConnected()) {
            return response()->json([
                'message' => 'Google Drive is not connected.',
                'error' => 'not_connected',
            ], 409);
        }

        try {
            $result = $resolve();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $this->errors->translate($e, "Could not load {$name}."),
                'error' => $this->errors->isExpiredGrant($e) ? 'reconnect_required' : 'google_error',
            ], 502);
        }

        if (is_array($result) && array_key_exists('next_page_token', $result)) {
            return response()->json([
                'data' => $result['data'],
                'meta' => ['next_page_token' => $result['next_page_token']],
            ]);
        }

        return response()->json(['data' => $result]);
    }
}
