<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_the_password_with_a_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'email' => 'jane@example.com',
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_it_revokes_existing_tokens_on_password_reset(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $user->createToken('pre-reset');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'email' => 'jane@example.com',
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_rejects_an_invalid_token(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/v1/reset-password', [
            'email' => 'jane@example.com',
            'token' => 'bogus-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422);
    }

    public function test_it_rejects_weak_passwords(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'email' => 'jane@example.com',
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }
}
