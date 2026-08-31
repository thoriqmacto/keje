<?php

namespace App\Enums;

/**
 * What YouTube currently says about a video we uploaded.
 *
 * Deliberately separate from YouTubeStatus. That enum tracks *our* pipeline —
 * did the upload job run, did it succeed — and it is frozen at the moment
 * videos.insert returned. This one tracks the video as it exists now, which
 * changes without us: a scheduled video publishes itself, and someone can set
 * a public video back to private from the YouTube app.
 *
 * Collapsing the two would produce a value that cannot answer either question.
 * "Failed" would mean both "our job errored" and "Google rejected the video",
 * and "Scheduled" would go on claiming a publish time that has long passed.
 */
enum YouTubeRemoteStatus: string
{
    /** publishAt is set and still in the future. */
    case Scheduled = 'scheduled';

    /** Live and visible to anyone. */
    case Published = 'published';

    case Private = 'private';
    case Unlisted = 'unlisted';

    /** Uploaded but YouTube has not finished processing it. */
    case Processing = 'processing';

    /** Google refused it — claim, terms of service, duplicate. */
    case Rejected = 'rejected';

    /** Deleted on YouTube, or no longer visible to this account. */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Private => 'Private',
            self::Unlisted => 'Unlisted',
            self::Processing => 'Processing',
            self::Rejected => 'Rejected by YouTube',
            self::Unavailable => 'Unavailable',
        };
    }

    /**
     * Derive the current state from what videos.list returned.
     *
     * privacyStatus alone is not enough: a scheduled video is private with a
     * publishAt, and reporting that as "Private" is exactly the confusion this
     * whole type exists to avoid.
     */
    public static function fromVideo(
        ?string $privacyStatus,
        ?string $uploadStatus,
        ?string $publishAt,
        ?\DateTimeInterface $now = null,
    ): self {
        $now ??= new \DateTimeImmutable;

        if ($uploadStatus === 'rejected' || $uploadStatus === 'failed') {
            return self::Rejected;
        }

        if ($uploadStatus === 'uploaded' || $uploadStatus === 'processing') {
            return self::Processing;
        }

        if ($privacyStatus === 'private' && $publishAt !== null) {
            $at = new \DateTimeImmutable($publishAt);

            // Past its publish time but still private: YouTube has not run the
            // publication yet, or it failed. Either way it is not "Published".
            return $at > $now ? self::Scheduled : self::Private;
        }

        return match ($privacyStatus) {
            'public' => self::Published,
            'unlisted' => self::Unlisted,
            'private' => self::Private,
            default => self::Unavailable,
        };
    }
}
