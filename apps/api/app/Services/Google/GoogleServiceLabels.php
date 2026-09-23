<?php

namespace App\Services\Google;

use App\Enums\GoogleService;

/**
 * The naming a message needs, without the classifier knowing the enum.
 *
 * The classifier composes sentences about a service — "YouTube will stop
 * working in about two days", "set YOUTUBE_CLIENT_ID" — and would otherwise
 * have to import GoogleService and reach through it for both. This is the
 * two strings it actually uses, which keeps the classifier a pure function of
 * text in and text out, and keeps it testable without the enum.
 */
final readonly class GoogleServiceLabels
{
    public function __construct(
        public string $label,
        public string $envPrefix,
    ) {}

    public static function for(GoogleService $service): self
    {
        return new self($service->label(), $service->envPrefix());
    }
}
