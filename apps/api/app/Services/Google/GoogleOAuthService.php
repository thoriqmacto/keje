<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\GoogleConnection;
use App\Models\User;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The OAuth dance and the resulting per-service connection record.
 *
 * State is generated here, cached against the user *and the service*, and
 * verified on callback. Without it the callback endpoint would accept an
 * authorization code obtained by anyone (CSRF into the victim's account);
 * without the service binding, a Drive consent could be redeemed at the
 * YouTube callback and stored as a YouTube connection.
 */
class GoogleOAuthService
{
    private const STATE_TTL_MINUTES = 15;

    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /** Consent URL plus the one-time state bound to this user and service. */
    public function authorizationUrl(User $user, GoogleService $service): string
    {
        $state = Str::random(40);

        Cache::put(
            $this->stateKey($service, $state),
            $user->id,
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );

        $client = $this->clients->base($service);
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Verify state for one service and return the user it was issued to.
     *
     * Single use: the key is forgotten immediately, so a replayed callback
     * fails even within the TTL. A state issued for another service does not
     * resolve here, because the service is part of the cache key.
     */
    public function consumeState(GoogleService $service, string $state): ?User
    {
        $userId = Cache::pull($this->stateKey($service, $state));

        return $userId === null ? null : User::find($userId);
    }

    /**
     * Exchange the authorization code and store the credentials encrypted.
     *
     * @throws RuntimeException
     */
    public function completeConnection(User $user, GoogleService $service, string $code): GoogleConnection
    {
        $client = $this->clients->base($service);
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google rejected the authorization: '.$token['error']);
        }

        $connection = $user->googleConnectionFor($service)
            ?? new GoogleConnection(['user_id' => $user->id, 'service' => $service]);

        // Google returns a refresh token only on first consent (or re-consent).
        // Never overwrite a good stored one with null.
        $refreshToken = $token['refresh_token'] ?? $connection->refresh_token;

        if (blank($refreshToken)) {
            throw new RuntimeException(
                'Google did not return a refresh token. Remove Keje from your Google account '
                .'permissions and connect again so the consent screen is shown.',
            );
        }

        $connection->forceFill([
            'user_id' => $user->id,
            'service' => $service,
            'access_token' => $token['access_token'] ?? null,
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes' => isset($token['scope']) ? explode(' ', (string) $token['scope']) : null,
            'connected_at' => now(),
        ])->save();

        if ($service === GoogleService::YouTube) {
            $this->syncYouTubeChannel($user, $connection);
        }

        return $connection->refresh();
    }

    /**
     * Record which YouTube channel this connection controls, so the
     * integrations page can warn before anything is uploaded to the wrong one.
     *
     * YouTube only. A Drive connection has no channel and must never trigger a
     * YouTube API call — it does not hold the scope for one.
     *
     * Best-effort: failing to read the channel must not undo a valid
     * connection, so a mismatch surfaces as "unknown" rather than a failure.
     */
    public function syncYouTubeChannel(User $user, GoogleConnection $connection): void
    {
        if ($connection->service !== GoogleService::YouTube) {
            return;
        }

        try {
            $youtube = new YouTube($this->clients->forUser($user, GoogleService::YouTube));
            $channels = $youtube->channels->listChannels('id,snippet', ['mine' => true]);
            $channel = $channels->getItems()[0] ?? null;

            if ($channel !== null) {
                $connection->youtube_channel_id = $channel->getId();
                $connection->youtube_channel_title = $channel->getSnippet()?->getTitle();
                $connection->save();
            }
        } catch (Throwable) {
            // Leaves the channel unknown, which the UI reports as unverified.
        }
    }

    public function disconnect(User $user, GoogleService $service): void
    {
        $connection = $user->googleConnectionFor($service);

        if ($connection === null) {
            return;
        }

        try {
            $this->clients->forUser($user, $service)->revokeToken($connection->refresh_token);
        } catch (Throwable) {
            // Already revoked or unreachable — either way, drop our copy.
        }

        // Only this service's row. The other service keeps its credentials.
        $connection->delete();
    }

    private function stateKey(GoogleService $service, string $state): string
    {
        return "google:oauth:{$service->value}:{$state}";
    }
}
