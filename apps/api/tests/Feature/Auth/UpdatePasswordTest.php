<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpdatePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->patchJson('/api/v1/me/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(401);
    }

    public function test_it_updates_password_with_correct_current(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $user->createToken('current')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_it_keeps_current_token_but_revokes_others(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other-1');
        $user->createToken('other-2');
        $this->assertDatabaseCount('personal_access_tokens', 3);

        $this->withHeader('Authorization', "Bearer {$current}")
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        // Only the current token survives.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_it_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $user->createToken('current')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'totally-wrong',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_it_rejects_weak_new_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $user->createToken('current')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'old-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_it_rejects_mismatched_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $user->createToken('current')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'different-thing',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
