<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * A title or subtitle cannot be laid out within its template's line and width
 * budget even at the minimum font size. Carries a message written for the user
 * — the API surfaces it as a 422 so the render is never silently cropped.
 */
class TextDoesNotFitException extends RuntimeException
{
    public function __construct(
        public readonly string $element,
        string $message,
    ) {
        parent::__construct($message);
    }
}
