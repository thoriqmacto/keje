<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configurePasswordResetUrl();
        $this->configureEmailVerificationUrl();
    }

    private function configureRateLimiters(): void
    {
        // Public auth endpoints (login, register, forgot/reset password).
        // Keyed by authenticated user (if any) else IP.
        RateLimiter::for('auth', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(
                (int) env('AUTH_THROTTLE_PER_MINUTE', 10)
            )->by((string) $key);
        });
    }

    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);

            return "{$frontend}/reset-password?{$query}";
        });
    }

    private function configureEmailVerificationUrl(): void
    {
        // Email verification link points at the backend so signature
        // validation runs there. The backend redirects to the frontend
        // with ?status=verified after success.
        VerifyEmail::createUrlUsing(function ($user): string {
            return URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes((int) env('VERIFICATION_LINK_TTL_MINUTES', 60)),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
            );
        });
    }
}
