<?php

namespace App\Models;

use App\Enums\GoogleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-side Google OAuth credentials for one user and one Google service.
 *
 * A user holds at most one connection per service (UNIQUE user_id+service):
 * YouTube and Drive are authorized through separate OAuth clients and neither
 * depends on the other.
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
            'service' => GoogleService::class,
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

    /** @param  Builder<self>  $query */
    public function scopeForService(Builder $query, GoogleService $service): void
    {
        $query->where('service', $service);
    }

    /** A connection is usable only while it still holds a refresh token. */
    public function isConnected(): bool
    {
        return filled($this->refresh_token) || filled($this->access_token);
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
     *
     * Null when this is not a YouTube connection, no expectation is
     * configured, or the channel is not yet known. Drive is never judged by
     * this — a channel mismatch must not block a backup.
     */
    public function matchesExpectedChannel(): ?bool
    {
        if ($this->service !== GoogleService::YouTube) {
            return null;
        }

        $expected = config('services.youtube.expected_channel_id');

        if (blank($expected) || blank($this->youtube_channel_id)) {
            return null;
        }

        return hash_equals((string) $expected, (string) $this->youtube_channel_id);
    }
}
