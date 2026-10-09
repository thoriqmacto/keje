<?php

namespace App\Models;

use App\Enums\GoogleService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function contentTopics(): HasMany
    {
        return $this->hasMany(ContentTopic::class);
    }

    public function speakers(): HasMany
    {
        return $this->hasMany(Speaker::class);
    }

    public function contentProjects(): HasMany
    {
        return $this->hasMany(ContentProject::class);
    }

    /** Up to one connection per Google service. */
    public function googleConnections(): HasMany
    {
        return $this->hasMany(GoogleConnection::class);
    }

    /**
     * This user's connection for one Google service, or null.
     *
     * Deliberately not a relation: YouTube and Drive are independent, and
     * asking for one must never imply anything about the other.
     *
     * ── Careful with Drive ──────────────────────────────────────────────
     *
     * Drive can hold several accounts now, so for Drive this returns the
     * highest-priority one rather than "the" connection. That is the right
     * default for asking whether Drive works at all, and the wrong thing for
     * anything that touches a specific file: a backup lives in one account,
     * and renaming or deleting it with another account's token simply fails.
     * Those callers use driveAccounts() or the project's own
     * driveConnection(), and the ordering below is what stops "the first
     * account" meaning a different one between two requests.
     */
    public function googleConnectionFor(GoogleService $service): ?GoogleConnection
    {
        return $this->googleConnections()
            ->forService($service)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    /**
     * Every Drive account, in the order Keje fills them.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, GoogleConnection>
     */
    public function driveAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->googleConnections()
            ->forService(GoogleService::Drive)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }
}
