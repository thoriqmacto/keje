<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * The render was stopped on request, part-way through.
 *
 * A separate exception from RenderFailedException on purpose, because the two
 * need opposite handling and they look identical from inside FFmpeg: both end
 * with a non-zero exit code and a truncated log. If a cancellation were
 * reported as a failure, the job's retry would start the encode over — which
 * is the exact opposite of what was asked — and the project would wear a red
 * "Render failed" badge for something nobody got wrong.
 */
class RenderCancelledException extends RuntimeException {}
