<?php

namespace App\Console\Commands;

use App\Exceptions\Media\TextDoesNotFitException;
use App\Exceptions\Media\UnusableMediaException;
use App\Models\ContentProject;
use App\Services\Media\FfmpegService;
use App\Services\Media\FfprobeService;
use App\Services\Media\RenderQueueHealth;
use App\Services\Media\VideoRenderer;
use App\Support\PathAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything a render needs, checked one project at a time.
 *
 * The render job validates the same things, but minutes later and on the
 * queue, where the only trace is a one-line error on the attempt. Running the
 * checks here answers "why won't this render" before spending a worker on it,
 * and prints the absolute paths so a missing file can be chased with `ls`.
 *
 * Read-only: it never uploads, queues, repairs or deletes anything.
 */
class RenderPreflightCommand extends Command
{
    protected $signature = 'render:preflight
        {project : The project UUID}
        {--permissions : Print owner, group and mode for every path checked}';

    protected $description = 'Check every prerequisite for rendering one project';

    /** `media:preflight` reads as the natural name next to media:diagnose. */
    protected $aliases = ['media:preflight'];

    private bool $ready = true;

    public function handle(
        FfmpegService $ffmpeg,
        FfprobeService $ffprobe,
        RenderQueueHealth $health,
        VideoRenderer $renderer,
    ): int {
        $project = ContentProject::with(['topic', 'speaker'])
            ->where('uuid', $this->argument('project'))
            ->first();

        if ($project === null) {
            $this->error('No project with that UUID. List them with: php artisan render:status');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<options=bold>'.($project->primary_title ?: 'Untitled project').'</>');
        $this->line('  '.$project->uuid.'  ·  status: '.$project->render_status->value);
        $this->newLine();

        $this->checkText($project);
        $this->checkSource($project, $ffprobe, 'audio', $project->source_audio_path);
        $this->checkSource($project, $ffprobe, 'background', $project->background_image_path);
        $this->checkLayout($project, $renderer);
        $this->checkTooling($ffmpeg, $ffprobe);
        $this->checkWritable($project);
        $this->checkQueue($health);

        $this->newLine();

        if (! $this->ready) {
            $this->line('<fg=red;options=bold>Not ready to render.</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('<fg=green;options=bold>Ready to render.</>');
        $this->line('Queue it from the studio, or: php artisan tinker');
        $this->newLine();

        return self::SUCCESS;
    }

    private function checkText(ContentProject $project): void
    {
        filled($project->primary_title)
            ? $this->good('Primary title', $project->primary_title)
            : $this->bad('Primary title', 'not set — a render needs one');
    }

    /**
     * The file the database claims exists.
     *
     * Both halves matter and fail differently: a column that was never set
     * means the upload never happened, while a set column whose file is gone
     * means the disk and the database disagree — a deploy that replaced
     * storage/, or a worker running from a different release directory.
     */
    private function checkSource(
        ContentProject $project,
        FfprobeService $ffprobe,
        string $label,
        ?string $relative,
    ): void {
        if (blank($relative)) {
            $this->bad(ucfirst($label), 'not uploaded');

            return;
        }

        $absolute = Storage::disk('local')->path($relative);

        // Missing, blocked and unreadable need three different fixes, and
        // PHP reports the first two identically. Re-uploading a recording
        // that is on disk behind a 0700 directory fixes nothing.
        $access = PathAccess::inspect($absolute, Storage::disk('local')->path(''));

        if ($access->status === PathAccess::MISSING) {
            $this->bad(ucfirst($label), "recorded as {$relative} but no file is there");
            $this->paths($relative, $absolute, $access);
            $this->hint('Re-upload it from the studio. If this keeps happening after a deploy, '
                .'storage/ is not shared between releases.');

            return;
        }

        if ($access->status === PathAccess::BLOCKED) {
            $this->bad(ucfirst($label), 'cannot be reached — a parent directory is closed');
            $this->paths($relative, $absolute, $access);
            $this->hint('Cannot traverse: '.$access->blockedAt);
            $this->hint('The file may well be there. '.PathAccess::currentUser()
                .' needs execute permission on that directory — see: php artisan media:diagnose');

            return;
        }

        if ($access->status === PathAccess::UNREADABLE) {
            $this->bad(ucfirst($label), 'exists but is not readable by '.PathAccess::currentUser());
            $this->paths($relative, $absolute, $access);

            return;
        }

        $size = $this->humanBytes((int) filesize($absolute));

        // ffprobe is the authority: a file of the right size can still be a
        // truncated upload that FFmpeg will refuse minutes into the render.
        try {
            $probe = $label === 'audio'
                ? $ffprobe->inspectAudio($absolute)
                : $ffprobe->inspectImage($absolute);
        } catch (UnusableMediaException $e) {
            // Reachable and readable, but not decodable — a different fix
            // again, so keep the permission facts alongside it.
            $this->bad(ucfirst($label), $e->getMessage());
            $this->paths($relative, $absolute, $access);

            return;
        } catch (Throwable $e) {
            $this->bad(ucfirst($label), 'could not be inspected: '.$e->getMessage());

            return;
        }

        $detail = $label === 'audio'
            ? sprintf('%s, %s, %ds', $size, $probe['codec'], (int) $probe['duration'])
            : sprintf('%s, %dx%d', $size, $probe['width'], $probe['height']);

        $this->good(ucfirst($label), $detail);
        $this->paths($relative, $absolute, $access);
    }

    /**
     * Where the file is and what its permissions are.
     *
     * Always shown on a failure, because that is when the modes matter;
     * behind --permissions otherwise, so a healthy run stays short.
     */
    private function paths(string $relative, string $absolute, PathAccess $access): void
    {
        $failed = ! $access->ok();

        if (! $failed && ! $this->option('permissions')) {
            $this->hint($absolute);

            return;
        }

        $this->detail('database path', $relative);
        $this->detail('absolute path', $absolute);
        $this->detail('exists', $access->status === PathAccess::MISSING ? 'no'
            : ($access->status === PathAccess::BLOCKED ? 'unknown — parent not traversable' : 'yes'));
        $this->detail('readable', $access->ok() ? 'yes' : 'no');

        if ($access->owner !== null) {
            $this->detail('owner', $access->owner);
            $this->detail('group', (string) $access->group);
            $this->detail('mode', (string) $access->mode);
        }
    }

    private function detail(string $label, string $value): void
    {
        $this->line(sprintf('    <fg=gray>%s %s</>', str_pad($label.' ', 22, '.'), $value));
    }

    /** Text that does not fit is refused, never cropped — so check it here too. */
    private function checkLayout(ContentProject $project, VideoRenderer $renderer): void
    {
        try {
            $renderer->resolveLayout($project);
            $this->good('Template layout', $project->template_key.' — all text fits');
        } catch (TextDoesNotFitException $e) {
            $this->bad('Template layout', $e->element.': '.$e->getMessage());
        } catch (Throwable $e) {
            $this->bad('Template layout', $e->getMessage());
        }
    }

    private function checkTooling(FfmpegService $ffmpeg, FfprobeService $ffprobe): void
    {
        $ffmpeg->isAvailable()
            ? $this->good('FFmpeg', (string) config('media.ffmpeg_path'))
            : $this->bad('FFmpeg', 'not executable at '.config('media.ffmpeg_path'));

        $ffprobe->isAvailable()
            ? $this->good('FFprobe', (string) config('media.ffprobe_path'))
            : $this->bad('FFprobe', 'not executable at '.config('media.ffprobe_path'));
    }

    /** The render writes text files, a temp MP4 and the final output. */
    private function checkWritable(ContentProject $project): void
    {
        $disk = Storage::disk('local');
        $probe = $project->storageDirectory().'/.preflight-'.bin2hex(random_bytes(4));

        try {
            $disk->put($probe, 'ok');
            $disk->delete($probe);
            $this->good('Project storage', 'writable by '.$this->user());
        } catch (Throwable $e) {
            $this->bad('Project storage', 'not writable: '.$e->getMessage());
        }
    }

    private function checkQueue(RenderQueueHealth $health): void
    {
        if (config('queue.default') === 'sync') {
            $this->bad('Queue', 'sync — FFmpeg must not run inside a web request');

            return;
        }

        $queue = $health->snapshot();

        if (! $queue['readable']) {
            $this->good('Queue', (string) config('queue.default'));

            return;
        }

        // A worker is not observable from here; untouched backlog is the
        // closest honest proxy, so report the depth and let render:status
        // deliver the verdict.
        $this->good('Queue', sprintf(
            '%s — %d waiting, %d claimed',
            config('queue.default'),
            $queue['pending'],
            $queue['reserved'],
        ));

        if ($queue['pending'] > 0 && ($queue['oldest_pending_seconds'] ?? 0) > 300) {
            $this->hint('Backlog is not moving. See: php artisan render:status');
        }
    }

    private function good(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>✔</> %-18s %s', $label, $detail));
    }

    private function bad(string $label, string $detail): void
    {
        $this->ready = false;
        $this->line(sprintf('  <fg=red>✖</> %-18s %s', $label, $detail));
    }

    private function hint(string $detail): void
    {
        $this->line('    <fg=gray>'.$detail.'</>');
    }

    private function user(): string
    {
        if (! function_exists('posix_geteuid')) {
            return 'this user';
        }

        return posix_getpwuid(posix_geteuid())['name'] ?? 'this user';
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 ** 2
            ? sprintf('%.1f MB', $bytes / 1024 ** 2)
            : sprintf('%.0f KB', $bytes / 1024);
    }
}
