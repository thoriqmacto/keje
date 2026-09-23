<?php

namespace App\Console\Commands;

use App\Models\GoogleConnection;
use App\Models\User;
use App\Notifications\GoogleConnectionNeedsAttention;
use App\Services\Google\GoogleConnectionHealth;
use App\Services\Google\GoogleOAuthClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Ask Google, on a schedule, whether the connections still work.
 *
 * This is the part that turns "you found out when the upload failed" into
 * "you found out an hour after it broke, with a day or two to fix it". It
 * probes every stored connection, records the verdict, and raises an alert
 * when something changes for the worse.
 *
 * ── Only on a change, and only for things worth saying ──────────────────
 *
 * Running hourly means the same dead token is seen twenty-four times a day,
 * and an alert each time is how somebody learns to filter the alerts. So a
 * notification goes out when the status *becomes* alertable, or degrades
 * (expiring_soon → renew_required is worth interrupting for a second time),
 * and otherwise at most once per alert_repeat_hours while it stays broken.
 *
 * `unreachable` never alerts. A network blip says nothing about the
 * credentials, and waking somebody for one trains them to ignore the wake.
 */
class GoogleHealthCheckCommand extends Command
{
    protected $signature = 'google:health
        {--user= : Only this user id}
        {--quiet-alerts : Probe and record, but send nothing}';

    protected $description = 'Check every Google connection and alert on anything that needs attention';

    public function handle(GoogleConnectionHealth $health): int
    {
        $connections = GoogleConnection::query()
            ->when($this->option('user'), fn ($q) => $q->where('user_id', $this->option('user')))
            ->with('user')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No Google connections to check.');

            return self::SUCCESS;
        }

        $problems = 0;

        foreach ($connections as $connection) {
            $user = $connection->user;

            if ($user === null) {
                continue;
            }

            $result = $health->check($user, $connection->service);
            $this->report($result);

            if (in_array($result['status'], GoogleOAuthClassifier::alertableStatuses(), true)) {
                $problems++;

                if (! $this->option('quiet-alerts')) {
                    $this->notifyIfNews($connection->fresh() ?? $connection, $user, $result);
                }
            }
        }

        // Non-zero so cron's own mail-on-failure works as a second channel for
        // anybody who has not configured an alert address.
        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function report(array $result): void
    {
        $line = sprintf('%-8s %-16s %s', $result['service'], $result['status'], $result['message']);

        match (true) {
            $result['status'] === GoogleOAuthClassifier::HEALTHY => $this->info($line),
            in_array($result['status'], GoogleOAuthClassifier::alertableStatuses(), true) => $this->error($line),
            default => $this->warn($line),
        };
    }

    /**
     * Notify, if this is news.
     *
     * Not named alert(): Command::alert() already exists and means "print a
     * banner", and silently overriding it would make every other command in
     * this application print mail instead.
     *
     * @param  array<string, mixed>  $result
     */
    private function notifyIfNews(GoogleConnection $connection, User $user, array $result): void
    {
        if (! $this->shouldAlert($connection, (string) $result['status'])) {
            return;
        }

        $address = config('services.google.health.alert_email') ?: $user->email;

        try {
            Notification::route('mail', $address)
                ->notify(new GoogleConnectionNeedsAttention($result));
        } catch (Throwable $e) {
            // A mailer that is not configured must not stop the check from
            // recording what it found — the banner is the channel that always
            // works, and it is already written by now.
            $this->warn("  could not send the alert: {$e->getMessage()}");

            return;
        }

        $connection->forceFill([
            'health_alerted_at' => Carbon::now(),
            'health_alerted_status' => $result['status'],
        ])->save();

        $this->line("  alerted {$address}");
    }

    /**
     * New, worse, or stale enough to repeat.
     *
     * The middle case is the one worth having: a connection that was warning
     * about a seven-day expiry and has now actually died has something new to
     * say, and staying quiet because *something* was already sent would hide
     * exactly the transition that matters.
     */
    private function shouldAlert(GoogleConnection $connection, string $status): bool
    {
        if ($connection->health_alerted_at === null) {
            return true;
        }

        if ($connection->health_alerted_status !== $status) {
            return true;
        }

        $repeatHours = max(1, (int) config('services.google.health.alert_repeat_hours', 24));

        return $connection->health_alerted_at->addHours($repeatHours)->isPast();
    }
}
