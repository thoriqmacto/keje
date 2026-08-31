<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleService;
use App\Http\Controllers\Controller;
use App\Services\Google\GoogleCatalogCache;
use App\Services\Google\GoogleErrorTranslator;
use App\Services\Google\YouTubeCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Read-only views of the connected YouTube channel's own catalog.
 *
 * Each resource is fetched independently so one failing call cannot take the
 * integrations page down with it — a quota error on playlists still leaves the
 * channel profile and recent uploads rendering, each reporting its own
 * problem. That is also why none of this lives in the connection-status
 * endpoint, which must stay a fast local read.
 */
class YouTubeCatalogController extends Controller
{
    public function __construct(
        private readonly YouTubeCatalogService $catalog,
        private readonly GoogleCatalogCache $cache,
        private readonly GoogleErrorTranslator $errors,
    ) {}

    public function channel(Request $request): JsonResponse
    {
        return $this->resource($request, 'channel', fn () => $this->cache->remember(
            $request->user(),
            GoogleService::YouTube,
            'channel',
            fn () => $this->catalog->channelProfile($request->user()),
        ));
    }

    /** Destination playlists only — the uploads playlist is not one. */
    public function playlists(Request $request): JsonResponse
    {
        $pageToken = (string) $request->query('page_token', '');

        return $this->resource($request, 'playlists', function () use ($request, $pageToken) {
            $user = $request->user();

            $page = $this->cache->remember(
                $user,
                GoogleService::YouTube,
                'playlists',
                fn () => $this->catalog->playlists($user, $pageToken ?: null),
                variant: $pageToken,
            );

            $channel = $this->cache->remember(
                $user,
                GoogleService::YouTube,
                'channel',
                fn () => $this->catalog->channelProfile($user),
            );

            return [
                'data' => $this->catalog->destinationPlaylists(
                    $page['data'],
                    $channel['uploads_playlist_id'] ?? null,
                ),
                'next_page_token' => $page['next_page_token'],
            ];
        });
    }

    public function categories(Request $request): JsonResponse
    {
        $region = (string) $request->query('region', (string) config('services.youtube.region_code'));

        return $this->resource($request, 'categories', fn () => $this->cache->remember(
            $request->user(),
            GoogleService::YouTube,
            'categories',
            fn () => $this->catalog->videoCategories($request->user(), $region),
            variant: $region,
        ));
    }

    public function languages(Request $request): JsonResponse
    {
        return $this->resource($request, 'languages', fn () => $this->cache->remember(
            $request->user(),
            GoogleService::YouTube,
            'languages',
            fn () => $this->catalog->languages($request->user()),
        ));
    }

    public function recentUploads(Request $request): JsonResponse
    {
        return $this->resource($request, 'recent_uploads', function () use ($request) {
            $user = $request->user();

            $channel = $this->cache->remember(
                $user,
                GoogleService::YouTube,
                'channel',
                fn () => $this->catalog->channelProfile($user),
            );

            $uploads = $channel['uploads_playlist_id'] ?? null;

            if (blank($uploads)) {
                return [];
            }

            return $this->cache->remember(
                $user,
                GoogleService::YouTube,
                'recent_uploads',
                fn () => $this->catalog->recentUploads($user, $uploads),
            );
        });
    }

    /**
     * Drop the cached catalog and read it again.
     *
     * Distinct from reconnecting: this asks Google for current data using the
     * grant already held, and never sends the user through consent.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->cache->flush($user, GoogleService::YouTube, [
            (string) config('services.youtube.region_code'),
        ]);

        return $this->channel($request);
    }

    /**
     * Run one catalog read, converting a Google failure into a reportable
     * section rather than a page-level error.
     *
     * @param  callable(): mixed  $resolve
     */
    private function resource(Request $request, string $name, callable $resolve): JsonResponse
    {
        $connection = $request->user()->googleConnectionFor(GoogleService::YouTube);

        if ($connection === null || ! $connection->isConnected()) {
            return response()->json([
                'message' => 'YouTube is not connected.',
                'error' => 'not_connected',
            ], 409);
        }

        if (! ($connection->capabilities()['read_channel'] ?? false)) {
            return response()->json([
                'message' => 'This YouTube connection cannot read the channel. Reconnect YouTube.',
                'error' => 'insufficient_scope',
            ], 409);
        }

        try {
            $result = $resolve();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $this->errors->translate($e, "Could not load {$name} from YouTube."),
                'error' => $this->errors->isExpiredGrant($e) ? 'reconnect_required' : 'google_error',
            ], 502);
        }

        // Paginated reads already carry their own envelope.
        if (is_array($result) && array_key_exists('next_page_token', $result)) {
            return response()->json([
                'data' => $result['data'],
                'meta' => ['next_page_token' => $result['next_page_token']],
            ]);
        }

        return response()->json(['data' => $result]);
    }
}
