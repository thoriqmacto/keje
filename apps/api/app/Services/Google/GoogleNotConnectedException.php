<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * Google is not connected, or the stored credentials no longer work. The
 * message is user-facing and always points at reconnecting.
 *
 * ── Why it carries an OAuth error code ──────────────────────────────────
 *
 * The message alone cannot tell a revoked refresh token from a mistyped
 * client secret, and those need opposite remedies: one is fixed by pressing
 * Reconnect, the other cannot be fixed by pressing Reconnect a hundred times.
 * Discarding Google's code meant every failure gave the first advice, so the
 * health check keeps it.
 *
 * Only the code — `invalid_grant`, `invalid_client` — which is a fixed value
 * from the OAuth spec. Never `error_description`, and never the response
 * body: a failed token exchange describes a request carrying the client
 * secret and the refresh token.
 */
class GoogleNotConnectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $oauthError = null,
    ) {
        parent::__construct($message);
    }
}
