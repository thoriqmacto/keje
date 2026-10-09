<?php

namespace App\Models;

use App\Enums\GoogleService;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Server-side Google OAuth credentials for one user, one Google service and
 * — for Drive — one Google account.
 *
 * YouTube and Drive are authorized through separate OAuth clients and neither
 * depends on the other. A user holds exactly one YouTube connection, because
 * uploading a lecture to the wrong channel cannot be undone, and as many
 * Drive connections as they have accounts: one free account's fifteen
 * gigabytes is shared with Gmail and Photos, which makes it a ceiling on how
 * much of a course can be backed up rather than a comfortable amount.
 *
 * Tokens use 'encrypted' casts so they are unreadable at rest, and are never
 * serialised into an API resource — see GoogleConnectionResource.
 */
class GoogleConnection extends Model
{
    use HasFactory;
    use HasUuid;

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

            // What the last check found. Stored rather than computed because
            // an alert is about a change, and noticing one needs a memory of
            // the previous answer — see GoogleConnectionHealth.
            'health_guidance' => 'array',
            'health_checked_at' => 'datetime',
            'health_failing_since' => 'datetime',
            'health_alerted_at' => 'datetime',

            // The quota as Google last reported it. See DriveStoragePool.
            'storage_limit' => 'integer',
            'storage_usage' => 'integer',
            'storage_checked_at' => 'datetime',
        ];
    }

    /**
     * Keep account_key in step with what identifies this connection.
     *
     * The column is what makes the unique index mean two different things at
     * once: exactly one YouTube connection per user, and one Drive connection
     * per Google account. Computing it on save rather than asking callers to
     * set it is deliberate — a forgotten assignment would read as '' and
     * collide with the YouTube row, and the failure would surface as a
     * confusing constraint violation during an OAuth callback.
     */
    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            if ($connection->service !== GoogleService::Drive) {
                $connection->account_key = '';

                return;
            }

            /*
             * The uuid is the fallback, so two accounts Google has not named
             * yet do not collide with each other — and it has to be assigned
             * here rather than relied on. HasUuid fills it on `creating`,
             * which fires *after* `saving`, so at this point a new row's uuid
             * is still empty and every unidentified account would key on ''
             * and collide. Setting it now is idempotent: HasUuid leaves a
             * uuid that is already there alone.
             */
            if (blank($connection->uuid)) {
                $connection->uuid = (string) Str::uuid();
            }

            $connection->account_key = mb_strtolower(
                (string) ($connection->google_account_email ?: $connection->uuid),
            );
        });
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

    /**
     * What to call this account on screen.
     *
     * The label if the user set one, otherwise the email, which is at least
     * unambiguous. Never "Google Drive" on its own — with several accounts
     * connected, a card that does not say which one it is is worse than no
     * card.
     */
    public function displayName(): string
    {
        return $this->account_label
            ?: $this->google_account_email
            ?: $this->service->label();
    }

    /**
     * Free bytes, or null when that cannot be said.
     *
     * Null covers two different situations that must not be confused with
     * zero: an account Google reports as unlimited, and one whose quota has
     * never been read. Treating either as "full" would route a backup away
     * from an account with room; treating them as "empty" would send one to
     * an account that cannot take it. Callers decide, which is why this
     * refuses to guess.
     */
    public function freeBytes(): ?int
    {
        if ($this->storage_limit === null || $this->storage_usage === null) {
            return null;
        }

        return max(0, $this->storage_limit - $this->storage_usage);
    }

    /** Google reports no quota at all for this account. */
    public function hasUnlimitedStorage(): bool
    {
        return $this->storage_checked_at !== null && $this->storage_limit === null;
    }

    /** A connection is usable only while it still holds a refresh token. */
    public function isConnected(): bool
    {
        return filled($this->refresh_token) || filled($this->access_token);
    }

    /**
     * What this specific grant permits, from the scopes Google returned.
     *
     * A connection made before a scope existed reports that capability as
     * false rather than failing at the call site, so the UI can offer a
     * reconnect for the one thing that is missing instead of implying the
     * whole integration is broken.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        if (! $this->isConnected()) {
            return array_map(static fn (): bool => false, $this->service->fullCapabilities());
        }

        return $this->service->capabilities($this->scopes ?? []);
    }

    /** True when reconnecting would grant something this connection lacks. */
    public function needsScopeUpgrade(): bool
    {
        foreach ($this->service->fullCapabilities() as $capability => $available) {
            if ($available && ! ($this->capabilities()[$capability] ?? false)) {
                return true;
            }
        }

        return false;
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
