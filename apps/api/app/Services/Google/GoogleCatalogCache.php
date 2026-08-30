<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches normalized Google catalog reads.
 *
 * Two reasons, both load-bearing. YouTube's Data API is quota-metered per
 * project per day, and a React page that refetches on every render would burn
 * it for nothing. And these calls are slow enough that hanging a form's
 * dropdown on a live round trip makes the studio feel broken.
 *
 * Keyed by user, service and resource, so one account's catalog can never be
 * served to another, and so disconnecting a service can drop everything it
 * cached without touching the other one.
 */
class GoogleCatalogCache
{
    /**
     * How long each resource stays fresh.
     *
     * Categories are effectively static — YouTube adds one every few years —
     * so they are cached for a day. Playlists and uploads change as the user
     * works and are kept short. The channel profile sits between.
     */
    private const TTL = [
        'channel' => 1800,      // 30 min
        'playlists' => 600,     // 10 min
        'categories' => 86400,  // 24 h
        'languages' => 86400,   // 24 h
        'recent_uploads' => 600,
        'about' => 1800,
        'backup_folder' => 1800,
        'backups' => 600,
    ];

    /** Every resource this cache knows how to hold, per service. */
    private const RESOURCES = [
        'youtube' => ['channel', 'playlists', 'categories', 'languages', 'recent_uploads'],
        'drive' => ['about', 'backup_folder', 'backups'],
    ];

    /**
     * Resolve a resource, calling Google only when nothing fresh is held.
     *
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(
        User $user,
        GoogleService $service,
        string $resource,
        Closure $resolve,
        string $variant = '',
    ): mixed {
        return Cache::remember(
            $this->key($user, $service, $resource, $variant),
            self::TTL[$resource] ?? 600,
            $resolve,
        );
    }

    /** Drop one resource, so the next read goes to Google. */
    public function forget(User $user, GoogleService $service, string $resource, string $variant = ''): void
    {
        Cache::forget($this->key($user, $service, $resource, $variant));
    }

    /**
     * Drop everything cached for one service.
     *
     * Called by an explicit refresh, and on disconnect so a later reconnect
     * cannot be served another grant's catalog.
     *
     * `$variants` lets a caller name the parameterised keys it created —
     * paginated playlist pages, per-region category lists — since a tag-less
     * cache store cannot enumerate them.
     *
     * @param  list<string>  $variants
     */
    public function flush(User $user, GoogleService $service, array $variants = []): void
    {
        foreach (self::RESOURCES[$service->value] ?? [] as $resource) {
            $this->forget($user, $service, $resource);

            foreach ($variants as $variant) {
                $this->forget($user, $service, $resource, $variant);
            }
        }
    }

    private function key(User $user, GoogleService $service, string $resource, string $variant): string
    {
        $suffix = $variant === '' ? '' : ':'.sha1($variant);

        return "google:catalog:{$user->id}:{$service->value}:{$resource}{$suffix}";
    }
}
