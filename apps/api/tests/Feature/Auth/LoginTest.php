<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['id', 'email'], 'token', 'token_type', 'expires_at'])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_invalid_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'secret-password',
        ])->assertStatus(422);
    }
}
