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
     * @param  list<string>  $arguments  FFmpeg arguments, excluding the binary
     * @param  Closure(float):void|null  $onProgress
     * @return array{exit_code:int, log:string}
     */
    public function run(array $arguments, ?float $totalDuration = null, ?Closure $onProgress = null): array
    {
        $process = new Process([$this->binary, ...$arguments], timeout: $this->timeout);

        $log = '';

        $process->run(function (string $type, string $buffer) use (&$log, $totalDuration, $onProgress): void {
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

        return [
            'exit_code' => (int) $process->getExitCode(),
            'log' => $this->tail($log),
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
