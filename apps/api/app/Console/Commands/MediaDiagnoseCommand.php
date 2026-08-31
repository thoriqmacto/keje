<?php

namespace App\Console\Commands;

use App\Enums\GoogleService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Media\FfmpegService;
use App\Services\Media\FfprobeService;
use App\Services\Media\TemplateRegistry;
use App\Support\PathAccess;
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

        $this->checkIdentity();
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
     * Who is running this, and whether that identity can be trusted to prove
     * anything about the ones that matter.
     */
    private function checkIdentity(): void
    {
        $user = PathAccess::currentUser();
        $groups = PathAccess::currentGroups();
        $runtime = (string) config('media.permissions.runtime_group');

        $this->good('Running as', $user.($groups ? ' · groups: '.implode(', ', $groups) : ''));

        if (PathAccess::isRoot()) {
            // root passes every permission check, so a clean report here says
            // nothing about the deploy user or www-data.
            $this->caution('Identity', 'running as root — permission checks below always pass. '
                .'Re-run as the deploy user to see what it really sees.');

            return;
        }

        if ($groups === null) {
            return;
        }

        in_array($runtime, $groups, true)
            ? $this->good('Runtime group', "{$user} is a member of {$runtime}")
            : $this->bad('Runtime group', "{$user} is not a member of {$runtime}. "
                ."Fix on the host: sudo usermod -aG {$runtime} {$user} — then start a new login session.");
    }

    /**
     * Directories PHP-FPM, the queue worker and the deploy user share.
     *
     * This is a shared-ownership problem, not a one-time setup step. A deploy
     * runs `view:cache` and friends as the deploy user, which leaves compiled
     * views and caches owned by *that* user; PHP-FPM then cannot rewrite them
     * at runtime. Blade surfaces it as the near-useless
     * "tempnam(): file created in the system's temporary directory", and
     * because it happens while rendering the error page, whatever the original
     * exception was is lost with it.
     *
     * Private media fails the other way round: PHP-FPM creates it, and the
     * deploy user running diagnostics cannot even enter the directory. Which
     * is why traversal is checked separately from writing — a directory can be
     * perfectly writable by its owner and still be a closed door to everyone
     * else, and that reads as "the file is missing".
     */
    private function checkRuntimeWritability(): void
    {
        $runtime = (string) config('media.permissions.runtime_group');

        // needsWrite: false where this user only has to read and traverse.
        $paths = [
            'Storage root' => [storage_path(), true],
            'App storage' => [storage_path('app'), true],
            'Private media' => [storage_path('app/private'), false],
            'Compiled views' => [storage_path('framework/views'), true],
            'Framework cache' => [storage_path('framework/cache'), true],
            'Sessions' => [storage_path('framework/sessions'), true],
            'Logs' => [storage_path('logs'), true],
            'Bootstrap cache' => [base_path('bootstrap/cache'), true],
        ];

        foreach ($paths as $label => [$path, $needsWrite]) {
            $this->checkSharedDirectory($label, $path, $needsWrite, $runtime);
        }
    }

    private function checkSharedDirectory(string $label, string $path, bool $needsWrite, string $runtime): void
    {
        if (! is_dir($path)) {
            $this->bad($label, "missing: {$path}");

            return;
        }

        $user = PathAccess::currentUser();
        $owner = PathAccess::owner($path) ?? '?';
        $group = PathAccess::group($path) ?? '?';
        $mode = PathAccess::mode($path) ?? '?';
        $facts = "owner {$owner}, group {$group}, mode {$mode}";

        // Traverse first: without it nothing inside can be reached, and every
        // other check below would be answering the wrong question.
        if (! is_executable($path)) {
            $this->bad($label, "not traversable by {$user} — {$facts}");
            $this->fix($path, $group === $runtime
                ? "{$user} belongs to {$group}, but the group has no execute bit."
                : "The directory belongs to group {$group}, not {$runtime}.");

            return;
        }

        if (! is_readable($path)) {
            $this->bad($label, "not readable by {$user} — {$facts}");
            $this->fix($path, null);

            return;
        }

        if ($needsWrite && ! is_writable($path)) {
            $this->bad($label, "not writable by {$user} — {$facts}");
            $this->fix($path, null);

            return;
        }

        // Group-write plus setgid is what keeps this working after the next
        // file is created. Without setgid, whoever writes next stamps their
        // own primary group on it and locks the other identity out again.
        $perms = @fileperms($path);
        $groupWritable = $perms !== false && ($perms & 0o020) !== 0;

        if ($needsWrite && ! $groupWritable) {
            $this->caution($label, "writable, but not group-writable — {$facts}");
            $this->fix($path, 'Whichever user writes first will lock the other out.');

            return;
        }

        if (! PathAccess::hasSetgid($path)) {
            $this->caution($label, "accessible, but setgid is not set — {$facts}");
            $this->fix($path, 'New files will take the creator\'s group instead of '.$runtime.'.');

            return;
        }

        $this->good($label, $facts);
    }

    /** The host command that fixes it — never run from here. */
    private function fix(string $path, ?string $because): void
    {
        if ($because !== null) {
            $this->line("      <fg=gray>{$because}</>");
        }

        $this->line("      <fg=gray>Suggested fix: sudo chmod 2770 {$path}</>");
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
                ? $this->bad('Media queue', $detail.' — no worker is consuming it. '
                    .'Install deploy/systemd/keje-worker.service, or check: systemctl status keje-worker')
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
