<?php

namespace App\Providers;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Observers\ContentProjectObserver;
use App\Services\Media\FfmpegService;
use App\Services\Media\FfprobeService;
use App\Services\Media\FontMetrics;
use App\Services\Media\TemplateRegistry;
use App\Services\Studio\RenderStalenessCascade;
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
        $this->registerMediaServices();
    }

    /**
     * Media services are singletons: TemplateRegistry and FontMetrics both
     * memoise (template files, glyph measurements) and that cache is worth
     * keeping for the length of a render.
     */
    private function registerMediaServices(): void
    {
        $this->app->singleton(TemplateRegistry::class, fn (): TemplateRegistry => new TemplateRegistry(
            basePath: (string) config('media.templates_path'),
            defaultKey: (string) config('media.default_template'),
        ));

        $this->app->singleton(FontMetrics::class, fn (): FontMetrics => new FontMetrics(
            fonts: (array) config('media.fonts'),
            pointScale: (float) config('media.font_point_scale'),
        ));

        $this->app->singleton(FfprobeService::class, fn ($app): FfprobeService => new FfprobeService(
            binary: (string) config('media.ffprobe_path'),
            timeout: (int) config('media.probe_timeout'),
        ));

        $this->app->singleton(FfmpegService::class, fn ($app): FfmpegService => new FfmpegService(
            binary: (string) config('media.ffmpeg_path'),
            timeout: (int) config('media.render_timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configurePasswordResetUrl();
        $this->configureEmailVerificationUrl();
        $this->configureRenderStaleness();
    }

    /**
     * Keep `content_projects.render_is_stale` true to the fingerprint.
     *
     * The flag is what lets the Studio list filter on "this video was made
     * from a render that no longer matches its project" — a comparison
     * against a hash computed in PHP, and therefore something SQL cannot work
     * out for itself.
     *
     * Wired here rather than as one observer because it has two triggers. The
     * project's own saves are the obvious one; the other is a topic or speaker
     * being renamed, which changes what gets drawn on the frame and so
     * invalidates renders belonging to projects that were never touched.
     * Without that second half the filter would miss the most common way a
     * render goes out of date, which is worse than not offering it.
     */
    private function configureRenderStaleness(): void
    {
        ContentProject::observe(ContentProjectObserver::class);

        ContentTopic::saved(fn (ContentTopic $topic) => app(RenderStalenessCascade::class)->topicSaved($topic));
        Speaker::saved(fn (Speaker $speaker) => app(RenderStalenessCascade::class)->speakerSaved($speaker));
    }

    private function configureRateLimiters(): void
    {
        // Public auth endpoints (login, register, forgot/reset password).
        // Keyed by authenticated user (if any) else IP.
        RateLimiter::for('auth', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(
                (int) config('auth.throttle_per_minute')
            )->by((string) $key);
        });
    }

    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
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
                Carbon::now()->addMinutes((int) config('auth.verification_link_ttl_minutes')),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
            );
        });
    }
}
