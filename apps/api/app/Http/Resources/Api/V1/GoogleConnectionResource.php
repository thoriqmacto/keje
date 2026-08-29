<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Connection *status* only.
 *
 * Access and refresh tokens are deliberately absent and must stay that way —
 * the frontend never needs them, and anything it receives can be read by the
 * browser.
 *
 * @mixin \App\Models\GoogleConnection
 */
class GoogleConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'connected' => filled($this->refresh_token) || filled($this->access_token),
            'account_email' => $this->google_account_email,
            'youtube_channel_id' => $this->youtube_channel_id,
            'youtube_channel_title' => $this->youtube_channel_title,
            // null when no expectation is configured or the channel is unknown.
            'channel_matches_expected' => $this->matchesExpectedChannel(),
            'expected_channel_id' => config('services.youtube.expected_channel_id'),
            'scopes' => $this->scopes ?? [],
            'connected_at' => $this->connected_at?->toIso8601String(),
            'configured' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret')),
        ];
    }
}
