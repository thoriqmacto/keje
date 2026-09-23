<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Whether a Google connection will actually work, asked before it matters.
 *
 * The old answer to "is YouTube connected" was "is there a row with a refresh
 * token in it", which stays true right up until the moment Google stops
 * accepting that token — so the first anyone heard of a dead grant was a
 * failed upload, after a render had already been spent on it.
 *
 * This asks the question properly, and there are two halves to it because
 * there are two things that can be known:
 *
 *   **A probe.** Exchange the refresh token and make one cheap call. That is
 *   the only way to learn whether a published app's grant is still good —
 *   Google publishes no expiry for it, so there is nothing to count down and
 *   nothing to infer. Run often, it turns "you found out at upload time" into
 *   "you found out within the hour".
 *
 *   **A clock**, in the one case there is one: a consent screen still in
 *   Testing expires its refresh tokens seven days after they are granted. See
 *   GoogleGrantClock. That case gets a real countdown and a warning before
 *   anything breaks, which is the only honest form of "tell me in advance".
 *
 * A probe costs one token exchange plus one API call — a YouTube channels
 * list is one quota unit against a daily ten thousand, and a Drive about.get
 * is free — so running it hourly is not a cost worth optimising.
 */
class GoogleConnectionHealth
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly GoogleOAuthClassifier $classifier,
        private readonly GoogleGrantClock $clock,
        private readonly GoogleApiReachability $reachability,
    ) {}

    /**
     * Probe one service for one user and record what came back.
     *
     * @return array{
     *     service:string, label:string, status:string, message:string,
     *     guidance:list<string>, checked_at:string, configured:bool, connected:bool,
     *     expires_at:?string, expires_in_seconds:?int, expires_in_human:?string,
     *     failing_since:?string
     * }
     */
    public function check(User $user, GoogleService $service): array
    {
        $labels = GoogleServiceLabels::for($service);
        $connection = $user->googleConnectionFor($service);

        if (! $this->clients->isConfigured($service)) {
            return $this->record($connection, $service, $this->classifier->notConfigured($labels), false, false);
        }

        if ($connection === null || blank($connection->refresh_token)) {
            return $this->record($connection, $service, $this->classifier->disconnected($labels), true, false);
        }

        try {
            // forUser() refreshes when the access token is stale, which is the
            // exchange that actually tests the refresh token. Forced here so a
            // connection whose access token happens to be fresh is still
            // genuinely checked rather than assumed good.
            $connection->forceFill(['token_expires_at' => null])->save();
            $client = $this->clients->forUser($user, $service);

            // Resolving proves the exchange worked. It does not prove the
            // token can reach the API this service needs — an API left
            // disabled in the Cloud console hands out perfectly valid tokens
            // that fail on first use, and that fails only here.
            $this->reachability->touch($client, $service);
        } catch (Throwable $e) {
            return $this->record($connection, $service, $this->classifier->classify($this->signature($e)), true, true);
        }

        // Working now. The only thing left that can be said is whether it is
        // working on a clock.
        $verdict = $this->clock->isExpiringSoon($connection)
            ? $this->classifier->expiringSoon($labels, (string) $this->clock->humanRemaining($connection))
            : $this->classifier->healthy();

        return $this->record($connection, $service, $verdict, true, true);
    }

    /**
     * Probe every service for one user.
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkAll(User $user): array
    {
        $results = [];

        foreach (GoogleService::cases() as $service) {
            $results[$service->value] = $this->check($user, $service);
        }

        return $results;
    }

    /**
     * What the last check found, without making another one.
     *
     * The UI asks this on every page load; probing there would put two Google
     * round trips in front of a banner. The scheduled check is what keeps it
     * current, and `checked_at` is reported so a stale answer can be seen for
     * what it is rather than trusted silently.
     *
     * @return array<string, mixed>
     */
    public function lastKnown(User $user, GoogleService $service): array
    {
        $labels = GoogleServiceLabels::for($service);
        $connection = $user->googleConnectionFor($service);

        if (! $this->clients->isConfigured($service)) {
            return $this->describe($connection, $service, $this->classifier->notConfigured($labels), false, false);
        }

        if ($connection === null || blank($connection->refresh_token)) {
            return $this->describe($connection, $service, $this->classifier->disconnected($labels), true, false);
        }

        // Never checked yet: say so rather than claiming health nobody
        // established. The scheduled run fills this in within the hour.
        if (blank($connection->health_status)) {
            return $this->describe($connection, $service, [
                'status' => GoogleOAuthClassifier::UNKNOWN_ERROR,
                'message' => 'This connection has not been checked yet.',
                'guidance' => ['Press Check now, or wait for the next scheduled check.'],
            ], true, true);
        }

        return $this->describe(
            $connection,
            $service,
            [
                'status' => $connection->health_status,
                'message' => (string) $connection->health_message,
                'guidance' => (array) ($connection->health_guidance ?? []),
            ],
            true,
            true,
        );
    }

    /**
     * Persist a verdict, and remember when the trouble started.
     *
     * `health_failing_since` is kept across checks so "broken" can become
     * "broken since Tuesday" — the difference between a notice somebody
     * skims and one they act on. It is cleared the moment a check passes.
     *
     * @param  array{status:string, message:string, guidance:list<string>}  $verdict
     * @return array<string, mixed>
     */
    private function record(
        ?GoogleConnection $connection,
        GoogleService $service,
        array $verdict,
        bool $configured,
        bool $connected,
    ): array {
        if ($connection !== null) {
            // Re-read first. forUser() refreshes through its own instance, so
            // this one's token columns are a snapshot from before the probe —
            // writing them back would undo the access token just stored.
            $connection = $connection->fresh() ?? $connection;

            $isBroken = in_array($verdict['status'], GoogleOAuthClassifier::brokenStatuses(), true);

            $connection->forceFill([
                'health_status' => $verdict['status'],
                'health_message' => $verdict['message'],
                'health_guidance' => $verdict['guidance'],
                'health_checked_at' => Carbon::now(),
                'health_failing_since' => $isBroken
                    ? ($connection->health_failing_since ?? Carbon::now())
                    : null,
            ])->save();
        }

        return $this->describe($connection, $service, $verdict, $configured, $connected);
    }

    /**
     * @param  array{status:string, message:string, guidance:list<string>}  $verdict
     * @return array<string, mixed>
     */
    private function describe(
        ?GoogleConnection $connection,
        GoogleService $service,
        array $verdict,
        bool $configured,
        bool $connected,
    ): array {
        return [
            'service' => $service->value,
            'label' => $service->label(),
            'status' => $verdict['status'],
            'message' => $verdict['message'],
            'guidance' => array_values($verdict['guidance']),
            'configured' => $configured,
            'connected' => $connected,
            'checked_at' => $connection?->health_checked_at?->toIso8601String(),
            'failing_since' => $connection?->health_failing_since?->toIso8601String(),

            // Null whenever no countdown exists, which is every published
            // consent screen. Null means "unknowable", never "plenty of time".
            'expires_at' => $connection === null ? null : $this->clock->expiresAt($connection)?->toIso8601String(),
            'expires_in_seconds' => $connection === null ? null : $this->clock->secondsRemaining($connection),
            'expires_in_human' => $connection === null ? null : $this->clock->humanRemaining($connection),
        ];
    }

    /**
     * The text a verdict is chosen from.
     *
     * The OAuth code comes first and matters most. A failed token exchange
     * arrives as GoogleNotConnectedException, whose message is deliberately
     * the same friendly sentence whatever went wrong — so without the code
     * every exchange failure would classify as unknown_error, and the whole
     * point of this service is telling those apart.
     *
     * The chain is flattened after it because the Google client wraps its
     * transport errors and the part worth matching on is usually innermost.
     * None of this is ever returned to a caller: it describes a request that
     * carried the client secret and the refresh token.
     */
    private function signature(Throwable $error): string
    {
        $parts = [];

        if ($error instanceof GoogleNotConnectedException && filled($error->oauthError)) {
            $parts[] = $error->oauthError;
        }

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $parts[] = $current->getMessage();
        }

        return implode(' ', $parts);
    }
}
