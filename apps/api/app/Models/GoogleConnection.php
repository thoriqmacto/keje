<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-side Google OAuth credentials for one user.
 *
 * Tokens use 'encrypted' casts so they are unreadable at rest, and are never
 * serialised into an API resource — see GoogleConnectionResource.
 */
class GoogleConnection extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Tokens are hidden as a second line of defence against accidental exposure. */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** True when the access token is missing or within the refresh skew window. */
    public function needsRefresh(int $skewSeconds = 60): bool
    {
        if (blank($this->access_token) || $this->token_expires_at === null) {
            return true;
        }

        return $this->token_expires_at->subSeconds($skewSeconds)->isPast();
    }

    /**
     * Whether the connected channel matches YOUTUBE_EXPECTED_CHANNEL_ID.
     * Null when no expectation is configured or the channel is not yet known.
     */
    public function matchesExpectedChannel(): ?bool
    {
        $expected = config('services.youtube.expected_channel_id');

        if (blank($expected) || blank($this->youtube_channel_id)) {
            return null;
        }

        return hash_equals((string) $expected, (string) $this->youtube_channel_id);
    }
}
