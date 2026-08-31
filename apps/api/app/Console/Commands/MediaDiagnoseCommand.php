<?php

namespace App\Console\Commands;

use App\Enums\GoogleService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Media\FfmpegService;
use App\Services\Media\FfprobeService;
use App\Services\Media\TemplateRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks that this host can actually render.
 *
 * Meant to be the first thing run after a deploy: every dependency the render
 * pipeline needs, verified in one pass. Exits non-zero when something critical
 * is missing so it can gate a deploy script.
 */
class MediaDiagnoseCommand extends Command
{
    protected $signature = 'media:diagnose';

    protected $description = 'Verify FFmpeg, fonts, storage and queue configuration for rendering';

    /** Critical failures make the command exit non-zero. */
    private bool $healthy = true;

    private bool $warned = false;

    public function handle(
        FfmpegService $ffmpeg,
        FfprobeService $ffprobe,
        TemplateRegistry $templates,
        GoogleClientFactory $clients,
    ): int {
        $this->newLine();
        $this->line('<options=bold>Keje media environment</>');
        $this->newLine();

        $this->checkBinary('FFmpeg', config('media.ffmpeg_path'), $ffmpeg->version());
        $this->checkBinary('FFprobe', config('media.ffprobe_path'), $ffprobe->version());
        $this->checkFonts();
        $this->checkTemplates($templates);
        $this->checkStorage();
        $this->checkUploadLimits();
        $this->checkRuntimeWritability();
        $this->checkQueue();
        $this->checkGoogle($clients);

        $this->newLine();

        if (! $this->healthy) {
            $this->line('<fg=red;options=bold>Media environment is not ready.</>');
            $this->line('Rendering will fail until the items marked FAIL are resolved.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($this->warned) {
            $this->line('<fg=yellow;options=bold>Media environment healthy, with warnings.</>');
        } else {
            $this->line('<fg=green;options=bold>Media environment healthy.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function checkBinary(string $label, string $path, ?string $version): void
    {
        if ($version === null) {
            $this->bad($label, "not found or not executable at {$path}");

            return;
        }

        $this->good($label, $version);
    }

    private function checkFonts(): void
    {
        foreach ((array) config('media.fonts') as $name => $path) {
            is_file($path)
                ? $this->good("Font ({$name})", $path)
                : $this->bad("Font ({$name})", "missing: {$path}");
        }
    }

    private function checkTemplates(TemplateRegistry $templates): void
    {
        $keys = $templates->keys();

        if ($keys === []) {
            $this->bad('Templates', 'no templates found in '.config('media.templates_path'));

            return;
        }

        $this->good('Templates', implode(', ', $keys));

        foreach ($keys as $key) {
            try {
                $template = $templates->get($key);
            } catch (Throwable $e) {
                $this->bad("Template ({$key})", $e->getMessage());

                continue;
            }

            // A template whose branding asset is missing renders silently
            // without its logo, so treat it as a failure rather than a warning.
            foreach (['branding.png', 'overlay.png'] as $asset) {
                $assetPath = $templates->assetPath($key, $asset);

                if (! is_file($assetPath)) {
                    $this->bad("Template asset ({$key}/{$asset})", "missing: {$assetPath}");
                }
            }

            unset($template);
        }
    }

    private function checkStorage(): void
    {
        $disk = Storage::disk('local');
        $probe = 'content/.diagnose-'.bin2hex(random_bytes(4));

        try {
            $disk->put($probe, 'ok');
            $readable = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            $readable
                ? $this->good('Private storage', $disk->path('content'))
                : $this->bad('Private storage', 'written but not readable');
        } catch (Throwable $e) {
            $this->bad('Private storage', 'not writable: '.$e->getMessage());
        }

        $free = @disk_free_space($disk->path(''));

        if ($free !== false) {
            $gb = $free / 1024 ** 3;
            $message = sprintf('%.1f GB free', $gb);

            // Renders are large; running out mid-encode wastes the whole job.
            $gb < 5
                ? $this->caution('Disk space', $message.' — low')
                : $this->good('Disk space', $message);
        }
    }

    /**
     * PHP's own ceilings on an upload, against what Keje advertises.
     *
     * A recording larger than upload_max_filesize but smaller than
     * post_max_size is dropped by PHP before Laravel sees it, and the request
     * comes back 422 as if the file were bad. That is invisible until someone
     * uploads a real lecture, so check it at deploy time instead.
     */
    private function checkUploadLimits(): void
    {
        $needed = (int) config('media.max_audio_mb');

        $limits = [
            'upload_max_filesize' => $this->bytes((string) ini_get('upload_max_filesize')),
            'post_max_size' => $this->bytes((string) ini_get('post_max_size')),
        ];

        foreach ($limits as $key => $bytes) {
            $shown = ini_get($key) ?: 'unset';

            // post_max_size = 0 means unlimited; upload_max_filesize = 0 does
            // not, it refuses every upload.
            if ($key === 'post_max_size' && $bytes === 0) {
                $this->good("PHP {$key}", 'unlimited');

                continue;
            }

            $bytes >= $needed * 1024 * 1024
                ? $this->good("PHP {$key}", $shown)
                : $this->bad("PHP {$key}", "{$shown} — smaller than MEDIA_MAX_AUDIO_MB ({$needed}M); uploads that size will be rejected as invalid");
        }

        // Nginx enforces its own ceiling and PHP cannot see it, so this is a
        // reminder rather than a check.
        $this->line("  <fg=gray>Nginx client_max_body_size must also be at least {$needed}M — not visible from PHP.</>");
    }

    /** Parse a PHP shorthand size ("512M", "8G") into bytes. */
    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Directories both the deploy user and the PHP-FPM user must be able to
     * write.
     *
     * This is a shared-ownership problem, not a one-time setup step. A deploy
     * runs `view:cache` and friends as the deploy user, which leaves compiled
     * views and caches owned by *that* user; PHP-FPM then cannot rewrite them
     * at runtime. Blade surfaces it as the near-useless
     * "tempnam(): file created in the system's temporary directory", and
     * because it happens while rendering the error page, whatever the original
     * exception was is lost with it.
     */
    private function checkRuntimeWritability(): void
    {
        $paths = [
            'Compiled views' => storage_path('framework/views'),
            'Framework cache' => storage_path('framework/cache'),
            'Sessions' => storage_path('framework/sessions'),
            'Logs' => storage_path('logs'),
            'Bootstrap cache' => base_path('bootstrap/cache'),
        ];

        foreach ($paths as $label => $path) {
            if (! is_dir($path)) {
                $this->bad($label, "missing: {$path}");

                continue;
            }

            if (! is_writable($path)) {
                $this->bad($label, 'not writable by '.$this->currentUser().": {$path}");

                continue;
            }

            $mode = @fileperms($path);

            // Group-write is what lets the deploy user and PHP-FPM share these
            // directories. Without it, whichever writes first locks the other
            // out on the next deploy.
            if ($mode !== false && ($mode & 0o020) === 0) {
                $this->caution(
                    $label,
                    sprintf(
                        'writable, but not group-writable (%s %s:%s) — PHP-FPM may be locked out',
                        substr(sprintf('%o', $mode), -4),
                        $this->ownerName(@fileowner($path)),
                        $this->groupName(@filegroup($path)),
                    ),
                );

                continue;
            }

            $this->good($label, 'writable');
        }
    }

    private function currentUser(): string
    {
        return $this->ownerName(function_exists('posix_geteuid') ? posix_geteuid() : null);
    }

    private function ownerName(int|false|null $uid): string
    {
        if ($uid === false || $uid === null) {
            return 'unknown';
        }

        return function_exists('posix_getpwuid')
            ? (posix_getpwuid($uid)['name'] ?? (string) $uid)
            : (string) $uid;
    }

    private function groupName(int|false|null $gid): string
    {
        if ($gid === false || $gid === null) {
            return 'unknown';
        }

        return function_exists('posix_getgrgid')
            ? (posix_getgrgid($gid)['name'] ?? (string) $gid)
            : (string) $gid;
    }

    private function checkQueue(): void
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            // sync would run FFmpeg inside the HTTP request.
            $this->bad('Queue', 'sync — rendering must not run in a web request. Use database or redis.');

            return;
        }

        $this->good('Queue', $connection);
        $this->good('Render timeout', config('media.render_timeout').'s');

        $this->checkQueueBacklog($connection);
    }

    /**
     * Whether anything is actually consuming the media queue.
     *
     * A render is dispatched with onQueue('media'), so a worker started as a
     * plain `queue:work` listens to `default` and never touches it. Nothing
     * errors: the job simply waits forever while the studio shows a progress
     * bar at 0%. The pending backlog is the only visible symptom, so report it
     * here rather than leaving it to be discovered one confused render later.
     */
    private function checkQueueBacklog(string $connection): void
    {
        if ($connection !== 'database') {
            // Redis and SQS keep their own state; depth is not readable the
            // same way, so say so rather than implying the queue is empty.
            $this->line('  <fg=gray>Queue depth is only readable for the database driver — check your broker directly.</>');

            return;
        }

        try {
            $table = config('queue.connections.database.table', 'jobs');

            $pending = DB::table($table)->where('queue', 'media')->whereNull('reserved_at')->count();
            $reserved = DB::table($table)->where('queue', 'media')->whereNotNull('reserved_at')->count();
            $oldest = DB::table($table)->where('queue', 'media')->whereNull('reserved_at')->min('created_at');
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            $this->caution('Queue backlog', 'could not be read: '.$e->getMessage());

            return;
        }

        if ($pending === 0) {
            $this->good('Media queue', $reserved > 0 ? "{$reserved} in progress" : 'empty');
        } else {
            $waited = $oldest === null ? null : (int) (time() - (int) $oldest);

            // Minutes of untouched backlog means nothing is listening; a job
            // enqueued seconds ago is just waiting its turn.
            $detail = "{$pending} waiting"
                .($waited !== null ? ', oldest '.$this->duration($waited) : '');

            $waited !== null && $waited > 300
                ? $this->bad('Media queue', $detail.' — no worker is consuming it. Run: php artisan queue:work --queue=media,default')
                : $this->caution('Media queue', $detail);
        }

        $failed > 0
            ? $this->caution('Failed jobs', "{$failed} — inspect with `php artisan queue:failed`")
            : $this->good('Failed jobs', 'none');
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 120) {
            return "{$seconds}s";
        }

        return $seconds < 7200
            ? intdiv($seconds, 60).'m'
            : intdiv($seconds, 3600).'h';
    }

    private function checkGoogle(GoogleClientFactory $clients): void
    {
        // Two independent OAuth clients: report them independently, because
        // either one can be usable while the other is not configured.
        foreach (GoogleService::cases() as $service) {
            $clients->isConfigured($service)
                ? $this->good($service->label(), 'OAuth client configured')
                : $this->caution(
                    $service->label(),
                    'not configured — set '.$service->envPrefix().'_CLIENT_ID, '
                        .$service->envPrefix().'_CLIENT_SECRET and '
                        .$service->envPrefix().'_REDIRECT_URI',
                );
        }

        $expected = config('services.youtube.expected_channel_id');

        $expected
            ? $this->good('YouTube channel', $expected)
            : $this->caution('YouTube channel', 'YOUTUBE_EXPECTED_CHANNEL_ID not set — uploads are not channel-verified');
    }

    private function good(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>✔</> %-28s %s', $label, $detail));
    }

    private function caution(string $label, string $detail): void
    {
        $this->warned = true;
        $this->line(sprintf('  <fg=yellow>!</> %-28s %s', $label, $detail));
    }

    private function bad(string $label, string $detail): void
    {
        $this->healthy = false;
        $this->line(sprintf('  <fg=red>✖</> %-28s %s', $label, $detail));
    }
}
