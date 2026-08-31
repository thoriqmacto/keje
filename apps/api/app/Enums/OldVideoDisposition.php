<?php

namespace App\Enums;

/**
 * What happens to the video being replaced.
 *
 * Deleting is permanent and takes the view count, the likes and every comment
 * with it. That is often what the user wants — a lecture published with the
 * wrong speaker name on screen should not stay up — but it is not a choice to
 * make on someone's behalf silently, so it is stored per replacement and
 * confirmed in the UI rather than read from config.
 */
enum OldVideoDisposition: string
{
    /** videos.delete. Permanent, and the comments and view count go with it. */
    case Delete = 'delete';

    /**
     * videos.update to private. The video and everything attached to it
     * survives, invisible, and can be restored by hand from YouTube Studio.
     */
    case KeepPrivate = 'keep_private';

    public function label(): string
    {
        return match ($this) {
            self::Delete => 'Delete the previous video permanently',
            self::KeepPrivate => 'Keep the previous video, set to private',
        };
    }

    /** Only permanent deletion needs the destructive-action confirmation. */
    public function isDestructive(): bool
    {
        return $this === self::Delete;
    }

    /** How the superseded publication is recorded in history. */
    public function publicationDisposition(): string
    {
        return match ($this) {
            self::Delete => 'deleted',
            self::KeepPrivate => 'kept_private',
        };
    }
}
