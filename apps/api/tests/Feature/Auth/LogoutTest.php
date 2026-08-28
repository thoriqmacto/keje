<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        // The underlying token row must be gone.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Rebuild the app so the next request doesn't reuse cached auth state,
        // then confirm the revoked token can no longer authenticate.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_unauthenticated_logout_is_rejected(): void
    {
        $this->postJson('/api/v1/logout')->assertStatus(401);
    }
}
