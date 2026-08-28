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
        config(['app.auth_throttle_per_minute' => 3]);
        // The rate limiter reads env via the AppServiceProvider closure, which
        // defaults to env('AUTH_THROTTLE_PER_MINUTE', 10). Override via the
        // env() helper for this test.
        putenv('AUTH_THROTTLE_PER_MINUTE=3');

        // Re-register the limiter using the new limit so it picks up the env.
        \Illuminate\Support\Facades\RateLimiter::for('auth', function ($request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(3)
                ->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

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
