<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use Illuminate\Support\Carbon;

/**
 * When a grant will stop working, in the one case where that is knowable.
 *
 * This is the honest answer to "warn me before the token expires", and it is
 * narrower than the question sounds. **Google does not publish an expiry for
 * a refresh token.** A refresh token from a published app is valid until
 * something ends it — the user revokes access, six months pass with no use,
 * the client is deleted — and none of those can be counted down to. Any
 * countdown claiming otherwise would be invented.
 *
 * There is exactly one real clock, and it is the one that bites in practice:
 *
 *   **A consent screen still in Testing issues refresh tokens that expire
 *   seven days after they are granted.**
 *
 * That is almost always what is behind a personal project whose uploads fail
 * every week or so. Keje records `connected_at`, so that seven days is a date
 * — a genuine countdown, not a guess — and this turns it into one.
 *
 * When the consent screen is published, this returns null rather than
 * inventing a number, and the health check falls back to the only other thing
 * that can be known: probing, often, and reporting the moment a probe fails.
 */
class GoogleGrantClock
{
    /**
     * How long a Testing-mode grant lasts, per Google's own documentation.
     *
     * Configurable only so a test can shorten it; nothing should raise it
     * hoping for more time, because the seven days is Google's and not ours.
     */
    public function lifetimeDays(): int
    {
        return max(1, (int) config('services.google.health.testing_grant_days', 7));
    }

    /** Whether the consent screen is still in Testing, per configuration. */
    public function isTestingMode(): bool
    {
        return (bool) config('services.google.health.consent_screen_testing', false);
    }

    /** How long before expiry to start warning. */
    public function warnWithinHours(): int
    {
        return max(1, (int) config('services.google.health.warn_within_hours', 48));
    }

    /**
     * When this grant runs out, or null when nothing can be promised.
     *
     * Null is the answer for a published consent screen and for a connection
     * that has no recorded grant time — and it means "no countdown exists",
     * never "plenty of time".
     */
    public function expiresAt(GoogleConnection $connection): ?Carbon
    {
        if (! $this->isTestingMode() || $connection->connected_at === null) {
            return null;
        }

        return $connection->connected_at->copy()->addDays($this->lifetimeDays());
    }

    /**
     * Seconds until the grant lapses. Negative once it has, null when there
     * is no clock to read.
     */
    public function secondsRemaining(GoogleConnection $connection, ?Carbon $now = null): ?int
    {
        $expiresAt = $this->expiresAt($connection);

        if ($expiresAt === null) {
            return null;
        }

        return (int) round(($now ?? Carbon::now())->diffInSeconds($expiresAt, false));
    }

    /**
     * Inside the warning window, and not already past.
     *
     * A grant that has already lapsed is not "expiring soon" — the probe will
     * have reported it as renew_required, which is a stronger and truer thing
     * to say than a countdown reading zero.
     */
    public function isExpiringSoon(GoogleConnection $connection, ?Carbon $now = null): bool
    {
        $seconds = $this->secondsRemaining($connection, $now);

        return $seconds !== null && $seconds > 0 && $seconds <= $this->warnWithinHours() * 3600;
    }

    /** "2 days", "6 hours" — for a sentence, not for arithmetic. */
    public function humanRemaining(GoogleConnection $connection, ?Carbon $now = null): ?string
    {
        $expiresAt = $this->expiresAt($connection);

        if ($expiresAt === null) {
            return null;
        }

        return ($now ?? Carbon::now())->diffForHumans($expiresAt, [
            'syntax' => Carbon::DIFF_ABSOLUTE,
            'parts' => 1,
        ]);
    }
}
