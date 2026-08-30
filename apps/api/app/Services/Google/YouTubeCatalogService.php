<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\User;
use Google\Service\YouTube;

/**
 * Reads the connected channel's own catalog: profile, playlists, categories,
 * recent uploads.
 *
 * Exists so the studio can offer real choices instead of asking anyone to type
 * `PLxxxx` or `27`. Everything is normalized here — the frontend never sees a
 * Google client object, so a field Google adds or deprecates does not reach
 * the UI contract.
 *
 * Quota discipline: every read uses the narrowest endpoint that answers the
 * question. `search.list` costs 100 units against a 10,000/day default and is
 * never used, because channels.list, playlists.list, playlistItems.list and
 * videoCategories.list each cost 1 and are authoritative for what they return.
 *
 * Results are cached by GoogleCatalogCache; this class always talks to Google.
 */
class YouTubeCatalogService
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /**
     * The authenticated channel.
     *
     * @return array<string, mixed>|null null when the account controls no channel
     */
    public function channelProfile(User $user): ?array
    {
        $api = $this->api($user);

        $response = $api->channels->listChannels(
            'snippet,contentDetails,statistics,status,brandingSettings',
            ['mine' => true],
        );

        $channel = $response->getItems()[0] ?? null;

        if ($channel === null) {
            return null;
        }

        $snippet = $channel->getSnippet();
        $stats = $channel->getStatistics();
        $status = $channel->getStatus();

        return [
            'channel_id' => (string) $channel->getId(),
            'title' => $snippet?->getTitle(),
            'description' => $snippet?->getDescription(),
            'custom_url' => $snippet?->getCustomUrl(),
            'thumbnail_url' => $this->thumbnail($snippet?->getThumbnails()),
            'country' => $snippet?->getCountry(),
            'default_language' => $snippet?->getDefaultLanguage(),

            // Counts arrive as strings; cast so the frontend can format them.
            'view_count' => $this->number($stats?->getViewCount()),
            'subscriber_count' => $this->number($stats?->getSubscriberCount()),
            'hidden_subscriber_count' => (bool) $stats?->getHiddenSubscriberCount(),
            'video_count' => $this->number($stats?->getVideoCount()),

            'uploads_playlist_id' => $channel->getContentDetails()?->getRelatedPlaylists()?->getUploads(),

            'privacy_status' => $status?->getPrivacyStatus(),
            'long_uploads_status' => $status?->getLongUploadsStatus(),
        ];
    }

    /**
     * Playlists the connected channel owns, one page at a time.
     *
     * `mine=true` rather than a search: it is authoritative, costs one unit,
     * and cannot return someone else's playlist.
     *
     * @return array{data: list<array<string, mixed>>, next_page_token: ?string}
     */
    public function playlists(User $user, ?string $pageToken = null): array
    {
        $api = $this->api($user);

        $params = ['mine' => true, 'maxResults' => 50];

        if (filled($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        $response = $api->playlists->listPlaylists('snippet,contentDetails,status', $params);

        $playlists = [];

        foreach ($response->getItems() as $playlist) {
            $snippet = $playlist->getSnippet();

            $playlists[] = [
                'id' => (string) $playlist->getId(),
                'title' => $snippet?->getTitle(),
                'description' => $snippet?->getDescription(),
                'thumbnail_url' => $this->thumbnail($snippet?->getThumbnails()),
                'item_count' => (int) ($playlist->getContentDetails()?->getItemCount() ?? 0),
                'privacy_status' => $playlist->getStatus()?->getPrivacyStatus(),
                'published_at' => $snippet?->getPublishedAt(),
            ];
        }

        return [
            'data' => $playlists,
            'next_page_token' => $response->getNextPageToken(),
        ];
    }

    /**
     * Playlists a video can actually be added to.
     *
     * The channel's uploads playlist is returned by the API but is a system
     * view of everything uploaded, not a destination — playlistItems.insert
     * against it fails. Offering it as a choice would produce a video that
     * uploads and then reports a playlist error every time.
     *
     * @param  list<array<string, mixed>>  $playlists
     * @return list<array<string, mixed>>
     */
    public function destinationPlaylists(array $playlists, ?string $uploadsPlaylistId): array
    {
        return array_values(array_filter(
            $playlists,
            static function (array $playlist) use ($uploadsPlaylistId): bool {
                if (filled($uploadsPlaylistId) && $playlist['id'] === $uploadsPlaylistId) {
                    return false;
                }

                // "UU"-prefixed ids are YouTube's per-channel system uploads
                // playlists; "PL" and "LL" are user-owned collections.
                return ! str_starts_with((string) $playlist['id'], 'UU');
            },
        ));
    }

    /**
     * Video categories that can be assigned in a region.
     *
     * Google returns categories that exist but cannot be set on an upload
     * (`assignable=false`); offering those would produce an upload rejected
     * for an invalid category.
     *
     * @return list<array{id:string, title:string}>
     */
    public function videoCategories(User $user, ?string $regionCode = null, ?string $language = null): array
    {
        $api = $this->api($user);

        $response = $api->videoCategories->listVideoCategories('snippet', [
            'regionCode' => $regionCode ?: (string) config('services.youtube.region_code'),
            'hl' => $language ?: (string) config('services.youtube.metadata_language'),
        ]);

        $categories = [];

        foreach ($response->getItems() as $category) {
            $snippet = $category->getSnippet();

            if (! $snippet?->getAssignable()) {
                continue;
            }

            $categories[] = [
                'id' => (string) $category->getId(),
                'title' => (string) $snippet->getTitle(),
            ];
        }

        usort($categories, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $categories;
    }

    /**
     * Languages YouTube accepts for snippet.defaultLanguage.
     *
     * @return list<array{id:string, title:string}>
     */
    public function languages(User $user, ?string $language = null): array
    {
        $api = $this->api($user);

        $response = $api->i18nLanguages->listI18nLanguages('snippet', [
            'hl' => $language ?: (string) config('services.youtube.metadata_language'),
        ]);

        $languages = [];

        foreach ($response->getItems() as $item) {
            $languages[] = [
                'id' => (string) ($item->getSnippet()?->getHl() ?? $item->getId()),
                'title' => (string) $item->getSnippet()?->getName(),
            ];
        }

        usort($languages, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $languages;
    }

    /**
     * The channel's most recent uploads.
     *
     * Read from the uploads playlist rather than search.list: same answer,
     * one quota unit instead of a hundred, and correctly ordered.
     *
     * @return list<array<string, mixed>>
     */
    public function recentUploads(User $user, string $uploadsPlaylistId, int $limit = 10): array
    {
        $api = $this->api($user);

        $response = $api->playlistItems->listPlaylistItems('snippet,contentDetails', [
            'playlistId' => $uploadsPlaylistId,
            'maxResults' => max(1, min($limit, 50)),
        ]);

        $uploads = [];

        foreach ($response->getItems() as $item) {
            $snippet = $item->getSnippet();
            $videoId = (string) ($item->getContentDetails()?->getVideoId() ?? '');

            if ($videoId === '') {
                continue;
            }

            $uploads[] = [
                'video_id' => $videoId,
                'title' => $snippet?->getTitle(),
                'thumbnail_url' => $this->thumbnail($snippet?->getThumbnails()),
                'published_at' => $item->getContentDetails()?->getVideoPublishedAt()
                    ?? $snippet?->getPublishedAt(),
                'url' => "https://www.youtube.com/watch?v={$videoId}",
            ];
        }

        return $uploads;
    }

    private function api(User $user): YouTube
    {
        return new YouTube($this->clients->forUser($user, GoogleService::YouTube));
    }

    /** The largest thumbnail Google offered, or null. */
    private function thumbnail(mixed $thumbnails): ?string
    {
        if ($thumbnails === null) {
            return null;
        }

        foreach (['getHigh', 'getMedium', 'getDefault'] as $size) {
            if (method_exists($thumbnails, $size) && ($url = $thumbnails->{$size}()?->getUrl())) {
                return (string) $url;
            }
        }

        return null;
    }

    /** Statistics arrive as strings, and are absent on a hidden count. */
    private function number(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
