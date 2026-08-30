<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards against the bug that sent every OAuth callback to localhost.
 *
 * `php artisan config:cache` stops Laravel from loading .env at all, so from
 * then on env() returns null everywhere except inside config/*.php — which
 * were evaluated back when the cache was written. An env() call in a
 * controller, provider or job therefore silently falls back to its default in
 * production, and only in production: the test suite never caches config, so
 * nothing here would otherwise notice.
 *
 * Laravel's own documentation is explicit that env() must not be called
 * outside configuration files. These tests make that rule enforceable.
 */
class ConfigCachingTest extends TestCase
{
    /** Directories whose code runs after config may have been cached. */
    private const RUNTIME_DIRS = ['app', 'routes', 'database'];

    #[Test]
    public function no_runtime_code_reads_env_directly(): void
    {
        $offenders = [];

        foreach (self::RUNTIME_DIRS as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    // `env(` as a call, not `$this->app->environment(` and not
                    // the word inside a comment.
                    if (! preg_match('/(?<![\w>$])env\s*\(/', $line)) {
                        continue;
                    }

                    if (preg_match('/^\s*(\/\/|\*|#)/', $line)) {
                        continue;
                    }

                    $offenders[] = sprintf(
                        '%s:%d  %s',
                        str_replace(base_path().'/', '', $file->getPathname()),
                        $number + 1,
                        trim($line),
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "env() outside config/ returns null once config is cached, so these\n"
            ."silently fall back to their defaults in production. Move the value\n"
            ."into a config file and read it with config():\n\n  "
            .implode("\n  ", $offenders)."\n",
        );
    }

    #[Test]
    public function the_frontend_url_is_configured_rather_than_defaulted(): void
    {
        // Every browser-facing redirect the API issues is built from this.
        $this->assertNotNull(config('app.frontend_url'));
        $this->assertNotSame('', config('app.frontend_url'));
    }

    #[Test]
    public function the_auth_knobs_are_configured(): void
    {
        $this->assertIsInt(config('auth.throttle_per_minute'));
        $this->assertGreaterThan(0, config('auth.throttle_per_minute'));

        $this->assertIsInt(config('auth.verification_link_ttl_minutes'));
        $this->assertGreaterThan(0, config('auth.verification_link_ttl_minutes'));
    }
}
