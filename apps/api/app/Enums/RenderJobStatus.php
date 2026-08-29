<?php

namespace App\Enums;

/**
 * Status of a single render attempt. Each retry creates a new RenderJob row so
 * the attempt history (and its FFmpeg diagnostics) survives.
 */
enum RenderJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
