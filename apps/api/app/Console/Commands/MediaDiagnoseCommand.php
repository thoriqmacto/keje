<?php

namespace App\Console\Commands;

use App\Services\Media\FfmpegService;
use App\Services\Media\FfprobeService;
use App\Services\Media\TemplateRegistry;
use Illuminate\Console\Command;
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
    ): int {
        $this->newLine();
        $this->line('<options=bold>Keje media environment</>');
        $this->newLine();

        $this->checkBinary('FFmpeg', config('media.ffmpeg_path'), $ffmpeg->version());
        $this->checkBinary('FFprobe', config('media.ffprobe_path'), $ffprobe->version());
        $this->checkFonts();
        $this->checkTemplates($templates);
        $this->checkStorage();
        $this->checkQueue();
        $this->checkGoogle();

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
    }

    private function checkGoogle(): void
    {
        $configured = filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));

        if (! $configured) {
            // Optional: rendering works fine without Google.
            $this->caution('Google', 'not configured — Drive backup and YouTube upload are unavailable');

            return;
        }

        $this->good('Google', 'client configured');

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
