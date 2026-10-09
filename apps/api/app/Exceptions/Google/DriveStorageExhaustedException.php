<?php

namespace App\Exceptions\Google;

use RuntimeException;

/**
 * No connected Drive account has room for this file.
 *
 * Deliberately not a generic upload failure. Every other reason a backup
 * fails is answered by retrying — a network blip, an expired token, a
 * transient Google error — and this one never is. Retrying a backup that
 * does not fit will not make it fit, so the message names the shortfall and
 * says what does help: connecting another account, or freeing space in one
 * that is already connected.
 */
class DriveStorageExhaustedException extends RuntimeException
{
    public function __construct(
        string $message,
        /** Bytes the file needs that no single account can offer. */
        public readonly int $requiredBytes = 0,
        /** The most any one account could take, which is what it is measured against. */
        public readonly ?int $largestFreeBytes = null,
    ) {
        parent::__construct($message);
    }
}
