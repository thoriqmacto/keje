<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_reset_link_to_a_known_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'jane@example.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reset_link_points_at_the_configured_frontend(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $url = (new ResetPassword('sample-token'))->toMail($user)->actionUrl;

        // The default FRONTEND_URL is http://localhost:3000 (from .env.example).
        $expectedPrefix = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/')
            . '/reset-password?';

        $this->assertStringStartsWith($expectedPrefix, $url);
        $this->assertStringContainsString('token=sample-token', $url);
        $this->assertStringContainsString('email=jane%40example.com', $url);
    }

    public function test_it_rejects_an_unknown_email_with_a_validation_error(): void
    {
        $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_requires_a_valid_email(): void
    {
        $this->postJson('/api/v1/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
