<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use App\Models\User;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The OAuth dance and the resulting connection record.
 *
 * State is generated here, cached against the user, and verified on callback —
 * without it the callback endpoint would accept an authorization code obtained
 * by anyone (CSRF into the victim's account).
 */
class GoogleOAuthService
{
    private const STATE_TTL_MINUTES = 15;

    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /** Consent URL plus the one-time state bound to this user. */
    public function authorizationUrl(User $user): string
    {
        $state = Str::random(40);

        Cache::put($this->stateKey($state), $user->id, now()->addMinutes(self::STATE_TTL_MINUTES));

        $client = $this->clients->base();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Verify state and return the user it was issued to.
     *
     * Single use: the key is forgotten immediately, so a replayed callback
     * fails even within the TTL.
     */
    public function consumeState(string $state): ?User
    {
        $key = $this->stateKey($state);
        $userId = Cache::pull($key);

        return $userId === null ? null : User::find($userId);
    }

    /**
     * Exchange the authorization code and store the credentials encrypted.
     *
     * @throws RuntimeException
     */
    public function completeConnection(User $user, string $code): GoogleConnection
    {
        $client = $this->clients->base();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google rejected the authorization: '.$token['error']);
        }

        $connection = $user->googleConnection ?? new GoogleConnection(['user_id' => $user->id]);

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
            'access_token' => $token['access_token'] ?? null,
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes' => isset($token['scope']) ? explode(' ', (string) $token['scope']) : null,
            'connected_at' => now(),
        ])->save();

        $this->syncIdentity($user, $connection);

        return $connection->refresh();
    }

    /**
     * Record which Google account and YouTube channel this connection controls,
     * so the integrations page can warn before anything is uploaded to the
     * wrong channel.
     *
     * Best-effort: failing to read the channel must not undo a valid connection.
     */
    public function syncIdentity(User $user, GoogleConnection $connection): void
    {
        try {
            $client = $this->clients->forUser($user);

            $oauth = new \Google\Service\Oauth2($client);
            $connection->google_account_email = $oauth->userinfo->get()->email ?? null;
        } catch (Throwable) {
            // Email is a nicety; keep going.
        }

        try {
            $youtube = new YouTube($this->clients->forUser($user));
            $channels = $youtube->channels->listChannels('id,snippet', ['mine' => true]);
            $channel = $channels->getItems()[0] ?? null;

            if ($channel !== null) {
                $connection->youtube_channel_id = $channel->getId();
                $connection->youtube_channel_title = $channel->getSnippet()?->getTitle();
            }
        } catch (Throwable) {
            // Requires youtube.readonly; a connection without it is still usable
            // for Drive, so this stays non-fatal.
        }

        $connection->save();
    }

    public function disconnect(User $user): void
    {
        $connection = $user->googleConnection;

        if ($connection === null) {
            return;
        }

        try {
            $this->clients->forUser($user)->revokeToken($connection->refresh_token);
        } catch (Throwable) {
            // Already revoked or unreachable — either way, drop our copy.
        }

        $connection->delete();
    }

    private function stateKey(string $state): string
    {
        return "google:oauth:state:{$state}";
    }
}
