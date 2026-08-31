<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * The video Keje expected to act on is not there any more.
 *
 * Deleted from YouTube Studio, or removed by Google. Distinguished from every
 * other failure because retrying cannot help — and because, during a
 * replacement, it means the disposal step has effectively already succeeded:
 * the goal was for that video to stop existing, and it has.
 */
class YouTubeVideoMissingException extends RuntimeException {}
