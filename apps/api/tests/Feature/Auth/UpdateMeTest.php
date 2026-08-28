<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpdateMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->patchJson('/api/v1/me', ['name' => 'New Name'])
            ->assertStatus(401);
    }

    public function test_it_updates_the_authenticated_users_name(): void
    {
        $user = User::factory()->create(['name' => 'Old']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_it_accepts_no_op_email_update_to_same_address(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['email' => 'jane@example.com'])
            ->assertOk();
    }

    public function test_it_rejects_email_already_taken_by_someone_else(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_rejects_invalid_email(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
