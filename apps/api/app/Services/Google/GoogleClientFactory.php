<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\GoogleConnection;
use App\Models\User;
use Google\Client;
use RuntimeException;

/**
 * Builds configured Google API clients, one OAuth client per service.
 *
 * YouTube and Drive have separate credentials and separate stored
 * connections, so every method here takes the service it is acting for.
 * Token refresh is written once and parameterised, not duplicated.
 */
class GoogleClientFactory
{
    public function isConfigured(GoogleService $service): bool
    {
        $config = $this->config($service);

        return filled($config['client_id'])
            && filled($config['client_secret'])
            && filled($config['redirect_uri']);
    }

    /** A bare client for one service: the consent URL and the code exchange. */
    public function base(GoogleService $service): Client
    {
        if (! $this->isConfigured($service)) {
            throw new RuntimeException(
                $service->label().' is not configured. Set '.$service->envPrefix().'_CLIENT_ID, '
                .$service->envPrefix().'_CLIENT_SECRET and '.$service->envPrefix().'_REDIRECT_URI.',
            );
        }

        $config = $this->config($service);

        $client = new Client;
        $client->setClientId((string) $config['client_id']);
        $client->setClientSecret((string) $config['client_secret']);
        $client->setRedirectUri((string) $config['redirect_uri']);
        $client->setScopes($service->scopes());

        // offline + consent is what actually yields a refresh token. Google
        // only returns one on first authorisation or on re-consent, so asking
        // for consent explicitly keeps reconnects working.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // Never incremental. include_granted_scopes lets Google fold scopes
        // already granted to this project back into the request, which is
        // exactly how a Drive consent would acquire YouTube scopes and be
        // rejected as "scopes that cannot be requested together".
        $client->setIncludeGrantedScopes(false);

        return $client;
    }

    /**
     * A client authenticated as the user for one service, refreshing the
     * access token first if it is missing or about to expire.
     *
     * @throws GoogleNotConnectedException
     */
    public function forUser(User $user, GoogleService $service): Client
    {
        $connection = $user->googleConnectionFor($service);

        if ($connection === null || blank($connection->refresh_token)) {
            throw new GoogleNotConnectedException(
                $service->label().' is not connected. Connect it from Settings → Integrations.',
            );
        }

        return $this->forConnection($connection);
    }

    /**
     * A client authenticated as one specific stored grant.
     *
     * The form everything Drive-shaped needs now that a user can hold several
     * Drive accounts. forUser() picks an account for you, which is fine for
     * "does Drive work at all" and wrong for anything touching a particular
     * file: a backup lives in exactly one account, and the other account's
     * token cannot read it, let alone rename it.
     *
     * @throws GoogleNotConnectedException
     */
    public function forConnection(GoogleConnection $connection): Client
    {
        if (blank($connection->refresh_token)) {
            throw new GoogleNotConnectedException(
                $connection->service->label().' is not connected. '
                .'Connect it from Settings → Integrations.',
            );
        }

        $service = $connection->service;
        $client = $this->base($service);

        if ($connection->needsRefresh()) {
            $this->refresh($client, $connection);
        }

        $expiresAt = $connection->token_expires_at ?? now()->addHour();

        $client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            // Seconds remaining, counted forwards. Carbon's diffInSeconds is
            // signed, so the operands matter: reversed, a valid token reports
            // a negative lifetime and is treated as already expired.
            'expires_in' => (int) max(1, round(now()->diffInSeconds($expiresAt, false))),
            // Google derives expiry from created + expires_in. Without
            // `created` it assumes the epoch, decides every token is stale and
            // burns a refresh round-trip on each API call.
            'created' => now()->timestamp,
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
            //
            // The code travels with the exception so the health check can tell
            // a dead grant (press Reconnect) from a rejected client (Reconnect
            // will never help). Only the code — a fixed OAuth value — never
            // error_description and never the body, which describes a request
            // carrying the client secret and the refresh token.
            throw new GoogleNotConnectedException(
                'The '.$connection->service->label().' connection is no longer valid. '
                .'Please reconnect it.',
                is_string($token['error']) ? $token['error'] : null,
            );
        }

        $connection->forceFill([
            'access_token' => $token['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            // Google usually omits refresh_token on refresh; keep the existing one.
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
        ])->save();
    }

    /** @return array{client_id:?string, client_secret:?string, redirect_uri:?string} */
    private function config(GoogleService $service): array
    {
        return [
            'client_id' => config($service->configKey().'.client_id'),
            'client_secret' => config($service->configKey().'.client_secret'),
            'redirect_uri' => config($service->configKey().'.redirect_uri'),
        ];
    }
}
