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
     */
    public function googleConnectionFor(GoogleService $service): ?GoogleConnection
    {
        return $this->googleConnections()->forService($service)->first();
    }
}
