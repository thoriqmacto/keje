<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\GoogleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Connection *status* for one Google service.
 *
 * Access and refresh tokens are deliberately absent and must stay that way —
 * the frontend never needs them, and anything it receives can be read by the
 * browser.
 *
 * @mixin \App\Models\GoogleConnection
 */
class GoogleConnectionResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly GoogleService $service,
        private readonly bool $configured,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $base = [
            'service' => $this->service->value,
            'label' => $this->service->label(),
            'configured' => $this->configured,
            'connected' => $this->resource?->isConnected() ?? false,
            'scopes' => $this->resource?->scopes ?? [],
            'connected_at' => $this->resource?->connected_at?->toIso8601String(),

            // Derived from the scopes Google actually granted, never from the
            // presence of configuration. A connection made before a scope was
            // added reports that one capability false and keeps the rest.
            'capabilities' => $this->resource?->capabilities()
                ?? array_map(static fn (): bool => false, $this->service->fullCapabilities()),
            'needs_scope_upgrade' => (bool) $this->resource?->needsScopeUpgrade(),
        ];

        if ($this->service !== GoogleService::YouTube) {
            return $base;
        }

        // Channel verification is a YouTube concern only: a mismatch must
        // never appear on, or block, the Drive connection.
        return [
            ...$base,
            'channel_id' => $this->resource?->youtube_channel_id,
            'channel_title' => $this->resource?->youtube_channel_title,
            // null when no expectation is configured or the channel is unknown.
            'channel_matches_expected' => $this->resource?->matchesExpectedChannel(),
            'expected_channel_id' => config('services.youtube.expected_channel_id'),
        ];
    }
}
