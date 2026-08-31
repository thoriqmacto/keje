<?php

namespace App\Enums;

/**
 * The three side-effecting steps of a replacement, as retry targets.
 *
 * A retry must resume, not restart. The distinction is the entire safety
 * property of this workflow: re-running the upload step on a replacement that
 * already holds a video id publishes a second copy of the lecture, and
 * re-running disposal on an already-deleted video turns a completed step into
 * a hard error.
 *
 * So the stage is never inferred from the status string, which is a display
 * value. It is derived from the facts — does a new video id exist, has the old
 * one been disposed of — by YouTubeReplacement::nextStage().
 */
enum ReplacementStage: string
{
    /** videos.insert. Only legal while new_video_id is null. */
    case Upload = 'upload';

    /** videos.delete, or videos.update to private. Needs a new video to exist. */
    case DisposeOld = 'dispose_old';

    /** Playlist, thumbnail, final privacy. Never touches videos.insert. */
    case Finalize = 'finalize';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'uploading the replacement',
            self::DisposeOld => 'removing the previous video',
            self::Finalize => 'finalising the publication',
        };
    }
}
