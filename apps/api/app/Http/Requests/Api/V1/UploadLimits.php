<?php

namespace App\Http\Requests\Api\V1;

/**
 * What PHP will actually accept, in the words of the person who has to fix it.
 *
 * When a file is larger than `upload_max_filesize` but the request still fits
 * inside `post_max_size`, PHP silently drops the file and hands Laravel an
 * empty field. The default message for that is "The audio failed to upload.",
 * which is true and useless: it looks like a network glitch, so the obvious
 * response is to try the same upload again, and again.
 *
 * Naming the limits turns that into a one-line server fix. These are ini
 * values, not secrets, and only the project's owner ever sees them.
 */
class UploadLimits
{
    /** @param  int  $allowedMb  the ceiling Keje itself enforces */
    public static function message(string $noun, int $allowedMb): string
    {
        return "The {$noun} did not finish uploading. Keje allows {$allowedMb} MB, but this server"
            .' accepts at most '.(ini_get('upload_max_filesize') ?: 'unknown').' per file and '
            .(ini_get('post_max_size') ?: 'unknown').' per request.'
            .' Raise PHP\'s upload_max_filesize and post_max_size (and Nginx\'s'
            .' client_max_body_size) to at least '.$allowedMb.'M, then reload PHP-FPM.';
    }
}
