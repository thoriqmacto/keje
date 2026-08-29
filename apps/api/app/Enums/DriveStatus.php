<?php

namespace App\Enums;

/**
 * Google Drive backup pipeline. Independent of RenderStatus and YouTubeStatus.
 */
enum DriveStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return $this === self::Uploading;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Uploading => 'Uploading',
            self::Uploaded => 'Uploaded',
            self::Failed => 'Failed',
        };
    }
}
