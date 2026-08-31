<?php

namespace App\Enums;

/**
 * How far a YouTube replacement has got.
 *
 * The order matters and the names are the sequence: a replacement uploads the
 * corrected video privately, disposes of the old one, and only then finalises
 * the new one into its intended visibility. Reading the status tells you what
 * exists on YouTube right now, which is the question that matters when
 * something has gone wrong halfway.
 *
 * `Failed` is deliberately not terminal. A replacement that could not delete
 * the old video is a recoverable situation with a private video sitting on the
 * channel — treating it as finished would strand that video and let a second
 * replacement start on top of it.
 */
enum ReplacementStatus: string
{
    /** Queued. Nothing has been sent to YouTube. */
    case Pending = 'pending';

    /** videos.insert is in flight. The old video is untouched. */
    case Uploading = 'uploading';

    /** The corrected video exists on YouTube, private. The old one is still current. */
    case Uploaded = 'uploaded';

    /** Disposing of the old video — deleting it, or setting it private. */
    case DisposingOld = 'disposing_old';

    /** The old video is gone (or hidden). The new one is authoritative but not yet configured. */
    case OldDisposed = 'old_disposed';

    /** Playlist, thumbnail and final privacy are being applied to the new video. */
    case Finalizing = 'finalizing';

    /** Deleting the temporary private copy after a cancellation. */
    case Cancelling = 'cancelling';

    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Recoverable. `failed_stage` says which step to retry. */
    case Failed = 'failed';

    /**
     * Terminal states release the per-project lock.
     *
     * Failed is absent on purpose: it still owns a private video on the
     * channel and still has work to finish or undo.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** A worker is acting on YouTube right now; a second must not start. */
    public function isInFlight(): bool
    {
        return in_array(
            $this,
            [self::Pending, self::Uploading, self::DisposingOld, self::Finalizing, self::Cancelling],
            true,
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Uploading => 'Uploading replacement',
            self::Uploaded => 'Replacement uploaded',
            self::DisposingOld => 'Removing previous video',
            self::OldDisposed => 'Previous video removed',
            self::Finalizing => 'Finalising publication',
            self::Cancelling => 'Cancelling',
            self::Completed => 'Replacement complete',
            self::Cancelled => 'Replacement cancelled',
            self::Failed => 'Replacement needs attention',
        };
    }
}
