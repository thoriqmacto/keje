<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * Something else already owns this project's YouTube state.
 *
 * Its own type so the controller can answer 409 rather than 422: the request
 * was well-formed and would have been valid a moment earlier, which is a
 * different thing from being wrong.
 */
class ReplacementConflictException extends RuntimeException {}
