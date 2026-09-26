<?php

namespace App\Services\Media;

use Closure;
use Symfony\Component\Process\Process;

/**
 * Safe execution of FFmpeg.
 *
 * Every argument is passed as an array element, so quoting, apostrophes and
 * Unicode in user text can never be reinterpreted as shell syntax — and in
 * fact user text never reaches argv at all: drawtext reads it from files.
 *
 * Isolating the process here also lets tests fake FFmpeg wholesale.
 */
class FfmpegService
{
    /** Keep only a diagnostic tail of FFmpeg output; logs must not grow unbounded. */
    public const LOG_TAIL_BYTES = 16000;

    /** How often $shouldAbort is consulted. See run(). */
    private const ABORT_CHECK_SECONDS = 2.0;

    /** How long FFmpeg gets to exit on SIGTERM before SIGKILL follows. */
    private const TERMINATE_GRACE_SECONDS = 10;

    public function __construct(
        private readonly string $binary,
        private readonly int $timeout = 7200,
    ) {}

    public function isAvailable(): bool
    {
        return is_file($this->binary) && is_executable($this->binary);
    }

    public function version(): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $process = new Process([$this->binary, '-version'], timeout: 30);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $first = strtok($process->getOutput(), "\n");

        return $first === false ? null : trim($first);
    }

    /**
     * Run FFmpeg, reporting progress as a 0..1 fraction.
     *
     * `-progress pipe:1 -nostats` makes FFmpeg emit machine-readable
     * `out_time_us=` lines on stdout; stderr keeps the human-readable log.
     *
     * ── Why this polls instead of handing run() a callback ───────────────
     *
     * A render is the longest thing this application does, and until now
     * nothing could stop one: `Process::run()` blocks until FFmpeg is
     * finished and gives no one a chance to change their mind. Starting the
     * process and polling it is what makes a cancellation possible — each
     * `isRunning()` drains the pipes, so progress keeps flowing, and between
     * reads there is somewhere to ask whether to stop.
     *
     * $shouldAbort is consulted at most every ABORT_CHECK_SECONDS rather than
     * on every poll: the caller's answer comes from the database, and asking
     * twenty times a second for the length of a two-hour encode would be a
     * lot of queries to learn "no" over and over.
     *
     * An aborted run returns `aborted: true` rather than throwing. The exit
     * code cannot be trusted to say what happened — FFmpeg killed by SIGTERM
     * looks like FFmpeg that failed — so the fact that we asked it to stop is
     * the only reliable evidence, and it has to travel back with the result.
     *
     * @param  list<string>  $arguments  FFmpeg arguments, excluding the binary
     * @param  Closure(float):void|null  $onProgress
     * @param  Closure():bool|null  $shouldAbort  polled; true stops FFmpeg
     * @return array{exit_code:int, log:string, aborted:bool}
     */
    public function run(
        array $arguments,
        ?float $totalDuration = null,
        ?Closure $onProgress = null,
        ?Closure $shouldAbort = null,
    ): array {
        $process = new Process([$this->binary, ...$arguments], timeout: $this->timeout);

        $log = '';

        $process->start(function (string $type, string $buffer) use (&$log, $totalDuration, $onProgress): void {
            if ($type === Process::OUT) {
                if ($onProgress !== null && $totalDuration !== null && $totalDuration > 0) {
                    $this->reportProgress($buffer, $totalDuration, $onProgress);
                }

                return;
            }

            $log .= $buffer;

            if (strlen($log) > self::LOG_TAIL_BYTES * 2) {
                $log = substr($log, -self::LOG_TAIL_BYTES);
            }
        });

        $aborted = false;
        $lastCheck = microtime(true);

        while ($process->isRunning()) {
            // Still enforced: updateStatus() does not check it, so without
            // this a runaway encode would poll here forever instead of
            // raising ProcessTimedOutException the way it used to.
            $process->checkTimeout();

            if ($shouldAbort !== null && (microtime(true) - $lastCheck) >= self::ABORT_CHECK_SECONDS) {
                $lastCheck = microtime(true);

                if ($shouldAbort()) {
                    $aborted = true;

                    /*
                     * No signal argument, and that is not an omission.
                     * Process::stop() always sends SIGTERM first — it
                     * hardcodes 15 rather than using the constant, because
                     * SIGTERM is only defined when ext-pcntl is loaded and
                     * this application does not require it. The optional
                     * second argument replaces the *escalation* signal, so
                     * passing SIGTERM there would mean a process that ignores
                     * SIGTERM gets sent it again instead of being killed.
                     *
                     * The default is what is wanted: SIGTERM, which FFmpeg
                     * handles by closing its output file and exiting, then
                     * SIGKILL if it is still there after the grace period.
                     */
                    $process->stop(self::TERMINATE_GRACE_SECONDS);

                    break;
                }
            }

            // Fine enough not to matter: progress is throttled again
            // downstream to media.progress.min_interval_seconds (1.5s by
            // default), so reading the pipes ten times a second is already an
            // order of magnitude more often than anything is persisted.
            usleep(100_000);
        }

        if (! $aborted) {
            // Drains what is left in the pipes and reaps the child. Skipped on
            // an abort, where stop() has already done both.
            $process->wait();
        }

        return [
            'exit_code' => (int) $process->getExitCode(),
            'log' => $this->tail($log),
            'aborted' => $aborted,
        ];
    }

    /**
     * Parse `out_time_us` / `out_time_ms` from an FFmpeg progress chunk.
     *
     * Only the last value in a chunk matters — intermediate ones are stale by
     * the time we read them.
     *
     * @param  Closure(float):void  $onProgress
     */
    private function reportProgress(string $buffer, float $totalDuration, Closure $onProgress): void
    {
        if (! preg_match_all('/out_time_(us|ms)=(-?\d+)/', $buffer, $matches, PREG_SET_ORDER)) {
            return;
        }

        $last = end($matches);
        $value = (int) $last[2];

        if ($value < 0) {
            return;
        }

        // Despite the name, FFmpeg's out_time_ms is also in microseconds.
        $seconds = $value / 1_000_000;

        $onProgress(min(1.0, $seconds / $totalDuration));
    }

    private function tail(string $log): string
    {
        return strlen($log) > self::LOG_TAIL_BYTES
            ? "…(truncated)…\n".substr($log, -self::LOG_TAIL_BYTES)
            : $log;
    }
}
