<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_sends_a_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated();

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_endpoint_sends_a_notification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Verification link sent.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_is_a_noop_for_already_verified_users(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Email already verified.');

        Notification::assertNothingSent();
    }

    public function test_signed_verification_link_marks_user_verified_and_redirects(): void
    {
        Event::fake();
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->get($url)
            ->assertRedirect(rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/verify-email?status=verified');

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_already_verified_link_still_redirects_ok(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()->subDay()]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->get($url)
            ->assertRedirectContains('status=verified');

        // Original verified-at is preserved (not bumped).
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_signature_is_rejected_by_signed_middleware(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );

        // Mangle the signature.
        $tampered = preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url);

        $this->get($tampered)->assertStatus(403);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_wrong_email_hash_redirects_with_invalid_status(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1('not-the-actual-email@example.com')],
        );

        $this->get($url)
            ->assertRedirectContains('status=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_me_payload_exposes_email_verified_at(): void
    {
        $verifiedAt = now()->subHour()->startOfSecond();
        $user = User::factory()->create(['email_verified_at' => $verifiedAt]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified_at', $verifiedAt->toIso8601String());
    }

    public function test_changing_email_invalidates_verification_and_resends(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/me', ['email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('user.email_verified_at', null);

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
