<?php

namespace App\Console\Commands;

use App\Enums\RenderJobStatus;
use App\Models\RenderJob;
use App\Services\Media\RenderQueueHealth;
use Illuminate\Console\Command;

/**
 * Where a render actually is, in one place.
 *
 * "Progress is stuck at 0" has several very different causes — nothing is
 * consuming the media queue, a worker picked the job up and died, FFmpeg is
 * genuinely encoding a long lecture — and telling them apart otherwise means
 * hand-querying three tables. Each attempt below gets a verdict and, where
 * there is one, the command that fixes it.
 *
 * Read-only. It never re-queues, cancels or mutates anything.
 */
class RenderStatusCommand extends Command
{
    protected $signature = 'render:status
        {--project= : Only attempts for this project UUID}
        {--limit=5 : How many recent attempts to show}
        {--log : Include the tail of the FFmpeg log for each attempt}';

    protected $description = 'Show recent render attempts and why they are or are not progressing';

    public function handle(RenderQueueHealth $health): int
    {
        $this->newLine();
        $this->queueSummary($health);

        $attempts = RenderJob::query()
            ->with('contentProject')
            ->when($this->option('project'), fn ($q, $uuid) => $q->whereHas(
                'contentProject',
                fn ($p) => $p->where('uuid', $uuid),
            ))
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($attempts->isEmpty()) {
            $this->line('  No render attempts recorded.');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($attempts as $attempt) {
            $this->attempt($attempt, $health);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function queueSummary(RenderQueueHealth $health): void
    {
        $this->line('<options=bold>Media queue</>');

        $queue = $health->snapshot();

        if (! $queue['readable']) {
            $this->line('  Depth is not readable for the '.config('queue.default')
                .' driver — inspect your broker directly.');
            $this->newLine();

            return;
        }

        $this->line(sprintf(
            '  %d waiting, %d claimed by a worker, %d failed job(s) overall',
            $queue['pending'],
            $queue['reserved'],
            $queue['failed'],
        ));

        // Untouched backlog is the signal that nothing is listening. A job
        // claimed seconds ago is simply being worked on.
        if ($queue['pending'] > 0 && ($queue['oldest_pending_seconds'] ?? 0) > 300) {
            $this->line('  <fg=red>Nothing has claimed the oldest job in '
                .intdiv($queue['oldest_pending_seconds'], 60).'m.</>');
            $this->line('  Is the worker service running?');
            $this->line('    <options=bold>systemctl status keje-worker</>  ·  '
                .'<options=bold>ps -eo user,group,pid,cmd | grep \'[q]ueue:work\'</>');
            $this->line('  To drain the backlog right now:');
            $this->line('    <options=bold>php artisan queue:work --queue=media,default --timeout=7200 --tries=2</>');
            // Running it by hand is a stopgap: it dies with the shell and does
            // not come back after a reboot, so say what the permanent fix is.
            $this->line('  That stops when the shell closes. Install the service so it does not:');
            $this->line('    <options=bold>deploy/systemd/keje-worker.service</> (see the README)');
        }

        if ($queue['failed'] > 0) {
            $this->line('  Inspect failures: <options=bold>php artisan queue:failed</>');
        }

        $this->newLine();
    }

    private function attempt(RenderJob $attempt, RenderQueueHealth $health): void
    {
        $project = $attempt->contentProject;
        $age = $attempt->created_at?->diffForHumans() ?? 'unknown';

        $this->line('<options=bold>'.($project?->primary_title ?: 'Untitled project').'</>');
        $this->line('  Project   '.($project?->uuid ?? '—'));
        $this->line('  Attempt   '.$attempt->status->value.', '.$attempt->progress_percent.'%, created '.$age);

        if ($attempt->started_at !== null) {
            $this->line('  Started   '.$attempt->started_at->diffForHumans()
                .($attempt->finished_at !== null
                    ? ', finished '.$attempt->finished_at->diffForHumans()
                    : ', still running'));
        }

        $this->verdict($attempt, $health);

        if (filled($attempt->error_message)) {
            $this->line('  <fg=red>Error</>     '.$attempt->error_message);
        }

        if ($this->option('log') && filled($attempt->ffmpeg_log)) {
            $this->newLine();
            $this->line('  <fg=gray>'.str_replace("\n", "\n  ", trim($attempt->ffmpeg_log)).'</>');
        }

        $this->newLine();
    }

    private function verdict(RenderJob $attempt, RenderQueueHealth $health): void
    {
        match ($attempt->status) {
            RenderJobStatus::Queued => $this->queuedVerdict($attempt, $health),

            // Progress is written as FFmpeg reports it, so a running attempt
            // that never moves off 0% is a worker that died mid-encode — the
            // row stays "running" because nothing was left to update it.
            RenderJobStatus::Running => $attempt->progress_percent === 0
                && $attempt->started_at?->diffInMinutes(now()) > 10
                    ? $this->say('red', 'Claimed but no progress in over 10 minutes. The worker may have '
                        .'been killed (OOM or a deploy restart). Check the worker log, then re-render.')
                    : $this->say('green', 'Encoding. Progress updates as FFmpeg reports it.'),

            RenderJobStatus::Succeeded => $this->say('green', 'Rendered successfully.'),
            RenderJobStatus::Failed => $this->say('red', 'Failed — see the error below'
                .(filled($attempt->ffmpeg_log) ? '; --log shows the FFmpeg output.' : '.')),
        };
    }

    private function queuedVerdict(RenderJob $attempt, RenderQueueHealth $health): void
    {
        $reason = $health->stallReason($attempt);

        $reason === null
            ? $this->say('green', 'Queued. Waiting for a worker — normal for the first moments.')
            : $this->say('red', $reason);
    }

    private function say(string $colour, string $message): void
    {
        $this->line("  <fg={$colour}>→</>        {$message}");
    }
}
