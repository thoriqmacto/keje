<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a single demo user so newcomers can try the starter immediately.
     *
     * Credentials:
     *   email    demo@example.com
     *   password password
     *
     * Run with: php artisan db:seed
     * (or `npm run setup` and answer "yes" when asked to seed)
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
