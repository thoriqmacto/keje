<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * ffprobe could not find a usable stream in an uploaded file — a ".mp3" that
 * is not really audio, a video container with no audio track, a corrupt image.
 */
class UnusableMediaException extends RuntimeException {}
