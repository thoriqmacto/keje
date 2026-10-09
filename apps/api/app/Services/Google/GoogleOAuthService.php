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
        private readonly GoogleCatalogCache $cache,
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

        /*
         * Which row this grant lands on, and that differs by service.
         *
         * YouTube replaces: a user has one channel they publish to, and a
         * second YouTube connection is how a lecture ends up on the wrong
         * one. Drive appends, because the whole point of adding an account is
         * that the first one is nearly full — replacing it would silently
         * discard the account holding every backup made so far.
         *
         * For Drive the row is only resolved after Google says who consented,
         * which is why this is a separate step rather than an ?? expression.
         */
        $connection = $service === GoogleService::Drive
            ? new GoogleConnection(['user_id' => $user->id, 'service' => $service])
            : ($user->googleConnectionFor($service)
                ?? new GoogleConnection(['user_id' => $user->id, 'service' => $service]));

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

            // A fresh grant clears whatever the last check concluded about the
            // old one. Without this, reconnecting to fix a dead token would
            // leave the banner up and the upload preflight still refusing,
            // until an hourly check happened to disagree with it.
            //
            // connected_at moving is also what restarts the seven-day clock a
            // Testing-mode consent screen puts on its refresh tokens.
            'health_status' => null,
            'health_message' => null,
            'health_guidance' => null,
            'health_checked_at' => null,
            'health_failing_since' => null,
            'health_alerted_at' => null,
            'health_alerted_status' => null,
        ])->save();

        // A reconnect can change what the grant permits, and points at
        // whichever account just consented. Anything cached under the previous
        // grant is not safe to keep.
        $this->cache->flush($user, $service);

        if ($service === GoogleService::YouTube) {
            $this->syncYouTubeChannel($user, $connection);
        }

        if ($service === GoogleService::Drive) {
            $connection = $this->settleDriveAccount($user, $connection);
        }

        return $connection->refresh();
    }

    /**
     * Work out which Google account just consented, and keep one row for it.
     *
     * Google's consent screen lets somebody pick any account they are signed
     * in to, and the authorization code says nothing about which. So the only
     * way to know is to ask, and asking matters for two reasons:
     *
     *  - **Re-consenting the same account must not create a second row.** Two
     *    connections to one Google account would show the same quota twice
     *    and double the pool's apparent free space, which is exactly the
     *    number somebody is about to trust. The fresh grant is merged onto
     *    the existing row instead, and the duplicate dropped.
     *
     *  - **A new account needs a place in the fill order.** It goes last, so
     *    adding an account never changes where the next backup would have
     *    gone.
     *
     * Identification failing is not fatal. The grant is real and works; it is
     * the label that is missing, and the quota sync will fill it in on its
     * next pass.
     */
    private function settleDriveAccount(User $user, GoogleConnection $connection): GoogleConnection
    {
        try {
            $identity = app(DriveQuotaSync::class)->identify($connection);
        } catch (Throwable) {
            return $connection;
        }

        $email = $identity['email'];

        if (blank($email)) {
            return $connection;
        }

        $existing = $user->googleConnections()
            ->forService(GoogleService::Drive)
            ->where('google_account_email', $email)
            ->whereKeyNot($connection->getKey())
            ->first();

        if ($existing !== null) {
            // Same account, consented again. Move the new credentials onto the
            // row that already has this account's label, priority and the
            // projects pointing at it, then drop the row just created.
            $existing->forceFill([
                'access_token' => $connection->access_token,
                'refresh_token' => $connection->refresh_token,
                'token_expires_at' => $connection->token_expires_at,
                'scopes' => $connection->scopes,
                'connected_at' => $connection->connected_at,
                'account_name' => $identity['name'] ?: $existing->account_name,
                'health_status' => null,
                'health_message' => null,
                'health_guidance' => null,
                'health_checked_at' => null,
                'health_failing_since' => null,
                'health_alerted_at' => null,
                'health_alerted_status' => null,
            ])->save();

            $connection->delete();

            return $existing;
        }

        $connection->forceFill([
            'google_account_email' => $email,
            'account_name' => $identity['name'],
            // Last in line. An account added because another is nearly full
            // should not take over from the one still being filled.
            'priority' => (int) $user->googleConnections()
                ->forService(GoogleService::Drive)
                ->whereKeyNot($connection->getKey())
                ->max('priority') + 1,
        ])->save();

        return $connection;
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

    /**
     * Drop one specific Drive account, leaving the others connected.
     *
     * The form the Drive page needs: "disconnect Drive" is the wrong question
     * once several accounts are attached, because the one that is nearly full
     * is usually the one somebody wants to keep and the spare is the one they
     * are tidying away.
     *
     * Projects backed up to this account keep their drive_file_id and lose
     * their drive_connection_id, by the foreign key's nullOnDelete. That is
     * the honest outcome: the file is still in somebody's Drive, Keje simply
     * no longer holds a token for it, and the UI can say so instead of
     * claiming the backup never happened.
     */
    public function disconnectAccount(GoogleConnection $connection): void
    {
        try {
            $this->clients->forConnection($connection)->revokeToken($connection->refresh_token);
        } catch (Throwable) {
            // Already revoked or unreachable — either way, drop our copy.
        }

        $user = $connection->user;

        $connection->delete();

        if ($user !== null) {
            $this->cache->flush($user, $connection->service);
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

        // Never serve one grant's catalog to the next connection.
        $this->cache->flush($user, $service);
    }

    private function stateKey(GoogleService $service, string $state): string
    {
        return "google:oauth:{$service->value}:{$state}";
    }
}
