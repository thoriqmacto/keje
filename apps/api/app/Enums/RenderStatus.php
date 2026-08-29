<?php

namespace App\Enums;

/**
 * Lifecycle of the FFmpeg render pipeline for a ContentProject.
 *
 * Deliberately independent of DriveStatus / YouTubeStatus: a failed Drive
 * backup must never drag the render back to "failed".
 */
enum RenderStatus: string
{
    case Draft = 'draft';
    case MediaReady = 'media_ready';
    case Queued = 'queued';
    case Rendering = 'rendering';
    case Rendered = 'rendered';
    case Failed = 'failed';

    /** Render is occupying a queue slot; a second dispatch must be refused. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Rendering], true);
    }

    /** A finished MP4 exists on disk for this project. */
    public function isRendered(): bool
    {
        return $this === self::Rendered;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::MediaReady => 'Media ready',
            self::Queued => 'Queued',
            self::Rendering => 'Rendering',
            self::Rendered => 'Rendered',
            self::Failed => 'Failed',
        };
    }
}
