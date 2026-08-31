<?php

namespace App\Exceptions\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The FFmpeg toolchain is missing or not executable on this host.
 *
 * Deliberately not an UnusableMediaException. When ffprobe cannot run, every
 * file looks unreadable, so reporting it as a validation error tells the
 * person their recording is corrupt and sends them off to re-encode a file
 * that was fine all along. This is the server's fault and says so: a 503, and
 * a message aimed at whoever administers the host.
 */
class MediaToolUnavailableException extends RuntimeException
{
    public static function at(string $binary): self
    {
        return new self("The media toolchain is unavailable on this server ({$binary}).");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage()
                .' This is a server configuration problem, not a problem with the uploaded file.'
                .' Install FFmpeg or correct MEDIA_FFPROBE_PATH, then run `php artisan media:diagnose`.',
        ], 503);
    }
}
