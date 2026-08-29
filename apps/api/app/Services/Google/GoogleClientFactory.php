<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use App\Models\User;
use Google\Client;
use RuntimeException;

/**
 * Builds configured Google API clients.
 *
 * Owns token refresh: callers ask for a client and get one with a valid access
 * token, or an exception. Refreshed tokens are written straight back to the
 * encrypted connection so the refresh happens once, not per request.
 */
class GoogleClientFactory
{
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
    }

    /** A bare client, used for the consent URL and the code exchange. */
    public function base(): Client
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Google is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI.',
            );
        }

        $client = new Client;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect_uri'));
        $client->setScopes((array) config('services.google.scopes'));

        // offline + consent is what actually yields a refresh token. Google
        // only returns one on first authorisation or on re-consent, so asking
        // for consent explicitly keeps reconnects working.
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /**
     * A client authenticated as the user, refreshing the access token first if
     * it is missing or about to expire.
     *
     * @throws GoogleNotConnectedException
     */
    public function forUser(User $user): Client
    {
        $connection = $user->googleConnection;

        if ($connection === null || blank($connection->refresh_token)) {
            throw new GoogleNotConnectedException(
                'Google is not connected. Connect it from Settings → Integrations.',
            );
        }

        $client = $this->base();

        if ($connection->needsRefresh()) {
            $this->refresh($client, $connection);
        }

        $client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            'expires_in' => max(1, $connection->token_expires_at?->diffInSeconds(now()) ?? 3600),
        ]);

        return $client;
    }

    /**
     * Exchange the refresh token for a new access token and persist it.
     *
     * @throws GoogleNotConnectedException
     */
    private function refresh(Client $client, GoogleConnection $connection): void
    {
        $token = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

        if (isset($token['error'])) {
            // Typically invalid_grant: the user revoked access, or the refresh
            // token expired. Never log the token itself.
            throw new GoogleNotConnectedException(
                'The Google connection is no longer valid. Please reconnect Google.',
            );
        }

        $connection->forceFill([
            'access_token' => $token['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            // Google usually omits refresh_token on refresh; keep the existing one.
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
        ])->save();
    }
}
