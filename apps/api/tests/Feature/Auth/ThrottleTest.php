<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts from a clean rate-limiter state.
        RateLimiter::clear('auth');
    }

    public function test_login_is_throttled_after_too_many_attempts(): void
    {
        // The limiter closure in AppServiceProvider reads this per request, so
        // setting it here exercises the real limiter rather than a substitute
        // registered by the test.
        config(['auth.throttle_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => 'nobody@example.com',
                'password' => 'whatever',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(429);
    }
}
