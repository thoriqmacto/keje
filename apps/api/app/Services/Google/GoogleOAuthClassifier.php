<?php

namespace App\Services\Google;

/**
 * Turns whatever Google said into a status Keje can act on.
 *
 * Before this existed there was one outcome for every failure: the upload job
 * threw "the connection is no longer valid, please reconnect", which is the
 * right advice for exactly one of the causes below and wasted advice for the
 * rest. A wrong client secret, a Drive API left disabled in the Cloud console
 * and a firewall all sent somebody off to mint a token that could never have
 * helped.
 *
 * ── Nothing here is Google's words ──────────────────────────────────────
 *
 * The classifier is handed error text and emits only fixed strings it chose
 * from that text. That is deliberate and not stylistic: a failed token
 * exchange describes a request carrying the client secret and the refresh
 * token, and Google's error bodies have been known to quote request
 * parameters back. Returning them to a browser would publish the credential
 * the failure was about.
 */
class GoogleOAuthClassifier
{
    /** Refreshed, reached the API, and the API answered. */
    public const HEALTHY = 'healthy';

    /** Working today, but on a clock that runs out — see GoogleGrantClock. */
    public const EXPIRING_SOON = 'expiring_soon';

    /** No client id, secret or redirect URI for this service. */
    public const NOT_CONFIGURED = 'not_configured';

    /** Configured, but nobody has granted access yet. */
    public const DISCONNECTED = 'disconnected';

    /** The refresh token is dead. The one case where reconnecting is the fix. */
    public const RENEW_REQUIRED = 'renew_required';

    public const INVALID_CLIENT = 'invalid_client';

    public const SCOPE_ERROR = 'scope_error';

    public const API_DISABLED = 'api_disabled';

    public const UNREACHABLE = 'unreachable';

    public const UNKNOWN_ERROR = 'unknown_error';

    /**
     * Statuses that mean "this will not work", as opposed to "not yet" or
     * "not for much longer".
     *
     * @return list<string>
     */
    public static function brokenStatuses(): array
    {
        return [
            self::RENEW_REQUIRED,
            self::INVALID_CLIENT,
            self::SCOPE_ERROR,
            self::API_DISABLED,
            self::UNKNOWN_ERROR,
        ];
    }

    /**
     * Statuses worth interrupting somebody about.
     *
     * `unreachable` is deliberately absent: a network blip says nothing about
     * the credentials, and an alert for every one of them is how people learn
     * to ignore the alerts that matter.
     *
     * @return list<string>
     */
    public static function alertableStatuses(): array
    {
        return [...self::brokenStatuses(), self::EXPIRING_SOON];
    }

    /**
     * Classify an error message.
     *
     * Order matters. invalid_grant is tested before the generic permission
     * branch because Google's rejection of a dead refresh token can carry
     * both, and blaming the scope would send somebody to the consent screen
     * to re-grant a permission they already have.
     *
     * @return array{status:string, message:string, guidance:list<string>}
     */
    public function classify(string $error): array
    {
        $haystack = mb_strtolower($error);

        if ($this->matches($haystack, [
            'could not resolve host', 'connection refused', 'connection timed out',
            'curl error', 'ssl certificate', 'network is unreachable', 'timed out',
            'operation timed out',
        ])) {
            return $this->unreachable();
        }

        if (str_contains($haystack, 'invalid_grant')) {
            return $this->renewRequired();
        }

        if ($this->matches($haystack, ['invalid_client', 'unauthorized_client'])) {
            return $this->invalidClient();
        }

        if ($this->matches($haystack, [
            'has not been used', 'accessnotconfigured', 'api has not been enabled',
            'is disabled for this project',
        ])) {
            return $this->apiDisabled();
        }

        if ($this->matches($haystack, [
            'insufficientpermissions', 'insufficient permission', 'insufficient scope',
            'insufficient authentication scopes', 'forbidden',
        ])) {
            return $this->scopeError();
        }

        return $this->unknown();
    }

    /** @return array{status:string, message:string, guidance:list<string>} */
    public function healthy(): array
    {
        return [
            'status' => self::HEALTHY,
            'message' => 'Connected and working.',
            'guidance' => [],
        ];
    }

    /** @return array{status:string, message:string, guidance:list<string>} */
    public function notConfigured(GoogleServiceLabels $labels): array
    {
        return [
            'status' => self::NOT_CONFIGURED,
            'message' => $labels->label.' is not configured on this server.',
            'guidance' => [
                'Set '.$labels->envPrefix.'_CLIENT_ID, '.$labels->envPrefix.'_CLIENT_SECRET and '
                    .$labels->envPrefix.'_REDIRECT_URI, then run php artisan config:cache.',
                'Reconnect from Settings → Integrations once they are set.',
            ],
        ];
    }

    /** @return array{status:string, message:string, guidance:list<string>} */
    public function disconnected(GoogleServiceLabels $labels): array
    {
        return [
            'status' => self::DISCONNECTED,
            'message' => $labels->label.' has never been connected.',
            'guidance' => [
                'Connect '.$labels->label.' from Settings → Integrations.',
            ],
        ];
    }

    /**
     * Still working, but on a clock.
     *
     * Only ever reached through GoogleGrantClock, which knows the one case
     * where a Google refresh token has a countdown at all.
     *
     * @return array{status:string, message:string, guidance:list<string>}
     */
    public function expiringSoon(GoogleServiceLabels $labels, string $humanRemaining): array
    {
        return [
            'status' => self::EXPIRING_SOON,
            'message' => $labels->label.' will stop working in about '.$humanRemaining.'.',
            'guidance' => [
                'This grant came from a consent screen still in Testing, and Google expires those refresh tokens seven days after they are issued.',
                'Reconnect '.$labels->label.' from Settings → Integrations to reset the seven days. Nothing else breaks when it lapses — uploads simply start failing.',
                'Publishing the OAuth consent screen in the Google Cloud console is what stops this recurring.',
            ],
        ];
    }

    /**
     * A dead refresh token: revoked, expired, or minted for another client.
     *
     * @return array{status:string, message:string, guidance:list<string>}
     */
    private function renewRequired(): array
    {
        return [
            'status' => self::RENEW_REQUIRED,
            'message' => 'Google rejected the stored credentials. They need to be granted again.',
            'guidance' => [
                'The refresh token has expired, been revoked, or no longer matches the OAuth client.',
                'Reconnect from Settings → Integrations. The new token is stored on the server and takes effect immediately — no .env edit and no deploy.',
                'A consent screen still in Testing issues refresh tokens that stop working after seven days. Publishing it is what stops this recurring.',
            ],
        ];
    }

    /**
     * The client id and secret do not form a valid pair, so the refresh token
     * was never examined. Suggesting a reconnect here would be wasted work.
     *
     * @return array{status:string, message:string, guidance:list<string>}
     */
    private function invalidClient(): array
    {
        return [
            'status' => self::INVALID_CLIENT,
            'message' => 'Google rejected the OAuth client credentials.',
            'guidance' => [
                'The stored grant was not examined — this is about the client id and secret, not the token.',
                'Check that the client secret matches its client id, and that neither carries a stray space, quote or newline.',
                'The client must be of type Web application; Desktop and mobile clients cannot use this grant.',
                'Run php artisan config:cache after correcting either value.',
            ],
        ];
    }

    /** @return array{status:string, message:string, guidance:list<string>} */
    private function scopeError(): array
    {
        return [
            'status' => self::SCOPE_ERROR,
            'message' => 'The credentials were accepted but do not carry the permissions Keje needs.',
            'guidance' => [
                'Reconnect from Settings → Integrations and accept every permission on the consent screen.',
                'A grant made before a permission was added keeps working for everything it does cover, so only the missing capability fails.',
            ],
        ];
    }

    /** @return array{status:string, message:string, guidance:list<string>} */
    private function apiDisabled(): array
    {
        return [
            'status' => self::API_DISABLED,
            'message' => 'The Google API this uses is not enabled for its Cloud project.',
            'guidance' => [
                'Enable it under APIs & Services in the Google Cloud console — YouTube Data API v3 for YouTube, Google Drive API for Drive.',
                'It can take a minute to take effect. Reconnecting will not help until it is enabled.',
            ],
        ];
    }

    /**
     * A network fault says nothing about the credentials, so this never
     * blames them — and never raises an alert. See alertableStatuses().
     *
     * @return array{status:string, message:string, guidance:list<string>}
     */
    private function unreachable(): array
    {
        return [
            'status' => self::UNREACHABLE,
            'message' => 'Google could not be reached, so the connection could not be checked.',
            'guidance' => [
                'This does not mean the connection is broken — nothing was learned either way.',
                'Check the server\'s outbound network access and try again.',
            ],
        ];
    }

    /** @return array{status:string, message:string, guidance:list<string>} */
    private function unknown(): array
    {
        return [
            'status' => self::UNKNOWN_ERROR,
            'message' => 'The connection could not be verified.',
            'guidance' => [
                'Check that the API is enabled for the OAuth client\'s Cloud project.',
                'Check that the client id, secret and redirect URI belong to one another.',
                'Reconnect from Settings → Integrations if the credentials themselves look right.',
            ],
        ];
    }

    /** @param list<string> $needles */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
