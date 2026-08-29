<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * FFmpeg failed, or the render could not be prepared. The message is safe to
 * show a user; raw FFmpeg output stays on the RenderJob for server-side triage.
 */
class RenderFailedException extends RuntimeException {}
