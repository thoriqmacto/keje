<?php

namespace App\Notifications;

use App\Services\Google\GoogleOAuthClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The early warning, sent once per problem rather than once per check.
 *
 * The whole point of this feature is arriving before the failed upload, so
 * this has to reach somebody who is not looking at the app. The in-app banner
 * covers the case where they are.
 *
 * Every string here comes from the classifier, which chose it from Google's
 * error text and never quotes it. Nothing in this mail describes the request
 * that failed, because that request carried the client secret and the refresh
 * token.
 */
class GoogleConnectionNeedsAttention extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $health */
    public function __construct(private readonly array $health) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = (string) $this->health['label'];
        $expiring = $this->health['status'] === GoogleOAuthClassifier::EXPIRING_SOON;

        $mail = (new MailMessage)
            ->subject($expiring
                // The subject line is the whole message for somebody reading
                // on a phone, so it carries the deadline rather than the word
                // "warning".
                ? "Keje: {$label} needs reconnecting soon"
                : "Keje: {$label} uploads will fail")
            ->greeting($expiring ? 'Heads up' : 'Something needs attention')
            ->line((string) $this->health['message']);

        if (filled($this->health['failing_since'] ?? null)) {
            $mail->line('This has been failing since '.$this->health['failing_since'].'.');
        }

        foreach ((array) ($this->health['guidance'] ?? []) as $line) {
            $mail->line('• '.$line);
        }

        // A link, because the fix is two clicks away and hunting for the page
        // is the part that gets postponed.
        return $mail
            ->action('Open Settings → Integrations', rtrim((string) config('app.frontend_url'), '/').'/settings/integrations')
            ->line($expiring
                ? 'Nothing is broken yet. Reconnecting now avoids a failed upload later.'
                : 'Uploads to this service will fail until it is reconnected.');
    }
}
