<?php

namespace App\Services\Google;

use Google\Service\Exception as GoogleServiceException;
use Throwable;

/**
 * Turns Google's errors into something a user can act on.
 *
 * A raw Google exception is a wall of JSON naming internal reasons; shown to
 * someone trying to publish a lecture it says nothing about what to do. The
 * full exception still goes to the log — the translation is only what reaches
 * the screen.
 *
 * Nothing here ever includes a token: the messages are built from the reason
 * code and this app's own vocabulary, never from echoing the request.
 */
class GoogleErrorTranslator
{
    /** Reason codes Google returns, mapped to what the user should do. */
    private const REASONS = [
        'quotaExceeded' => 'The YouTube API quota for today is exhausted. This resets at midnight Pacific time.',
        'rateLimitExceeded' => 'YouTube is rate-limiting requests. Try again in a moment.',
        'userRateLimitExceeded' => 'YouTube is rate-limiting requests. Try again in a moment.',
        'playlistNotFound' => 'That playlist no longer exists on the connected channel.',
        'playlistItemsNotAccessible' => 'Keje cannot add videos to that playlist.',
        'playlistOperationUnsupported' => 'That playlist does not accept videos added this way.',
        'videoAlreadyInPlaylist' => 'The video is already in that playlist.',
        'videoNotFound' => 'That video no longer exists on the connected channel.',
        'forbidden' => 'The connected Google account is not allowed to do that.',
        'insufficientPermissions' => 'The YouTube connection is missing a permission. Reconnect YouTube.',
        'authError' => 'The Google connection has expired. Reconnect it from Settings → Integrations.',
        'notFound' => 'Google could not find that resource.',
    ];

    /** A human-facing sentence for any Google failure. */
    public function translate(Throwable $e, string $fallback = 'Google returned an error.'): string
    {
        if ($this->isExpiredGrant($e)) {
            return 'The Google connection has expired. Reconnect it from Settings → Integrations.';
        }

        foreach ($this->reasons($e) as $reason) {
            if (isset(self::REASONS[$reason])) {
                return self::REASONS[$reason];
            }
        }

        return $fallback;
    }

    /** True when the video was already a member — success, not failure. */
    public function isAlreadyInPlaylist(Throwable $e): bool
    {
        return in_array('videoAlreadyInPlaylist', $this->reasons($e), true);
    }

    /** True when the playlist itself is gone or unusable as a destination. */
    public function isPlaylistUnavailable(Throwable $e): bool
    {
        return (bool) array_intersect(
            ['playlistNotFound', 'playlistItemsNotAccessible', 'playlistOperationUnsupported'],
            $this->reasons($e),
        );
    }

    /**
     * The refresh token is dead — the user must consent again.
     *
     * Distinguished from every other failure because it is the only one where
     * reconnecting is the answer. A quota error must never disconnect anyone.
     */
    public function isExpiredGrant(Throwable $e): bool
    {
        if ($e instanceof GoogleNotConnectedException) {
            return true;
        }

        if ($e instanceof GoogleServiceException && $e->getCode() === 401) {
            return true;
        }

        return str_contains($e->getMessage(), 'invalid_grant');
    }

    /**
     * Reason codes carried by a Google service exception.
     *
     * @return list<string>
     */
    private function reasons(Throwable $e): array
    {
        if (! $e instanceof GoogleServiceException) {
            return [];
        }

        // A 5xx with an empty body has no error array at all.
        $errors = $e->getErrors();

        if (! is_array($errors)) {
            return [];
        }

        $reasons = [];

        foreach ($errors as $error) {
            if (is_array($error) && isset($error['reason'])) {
                $reasons[] = (string) $error['reason'];
            }
        }

        return $reasons;
    }
}
