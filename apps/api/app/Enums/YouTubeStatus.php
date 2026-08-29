<?php

namespace App\Enums;

/**
 * YouTube publication pipeline. Independent of RenderStatus and DriveStatus.
 *
 * "Scheduled" means the video is uploaded as private with a publishAt set —
 * YouTube performs the actual publication, we never poll to flip it public.
 */
enum YouTubeStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return $this === self::Uploading;
    }

    /** A video already exists on YouTube; re-uploading would duplicate it. */
    public function hasVideo(): bool
    {
        return in_array($this, [self::Uploaded, self::Scheduled, self::Published], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Uploading => 'Uploading',
            self::Uploaded => 'Uploaded',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }
}
