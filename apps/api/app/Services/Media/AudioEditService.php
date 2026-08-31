<?php

namespace App\Services\Media;

use Illuminate\Validation\ValidationException;

/**
 * Removed sections of the recording, as decisions rather than as edits.
 *
 * The uploaded MP3 is never rewritten. A cut list says which spans to leave
 * out, and the renderer rebuilds the kept spans at encode time. That keeps the
 * original recoverable — a mis-typed timestamp costs a re-render, not the
 * lecture — and makes the edit list a render input like any other, so changing
 * it correctly invalidates a finished MP4.
 *
 * "Cut 18 → 23" removes those five seconds: the result is 0–18 followed by
 * 23–end. It is not a trim down to that range, which is the opposite operation
 * and the easier one to build by accident.
 *
 * Nothing here accepts a filter, an expression, or any other FFmpeg syntax.
 * The request carries validated numbers; this builds the graph.
 */
class AudioEditService
{
    /** Ranges shorter than this are noise from a mis-click, not an edit. */
    private const MIN_CUT_SECONDS = 0.05;

    /**
     * Ranges closer together than this are merged: FFmpeg gains nothing from
     * a 20-millisecond island between two cuts, and the arithmetic of many
     * near-adjacent segments is where rounding drift accumulates.
     */
    private const MERGE_GAP_SECONDS = 0.01;

    /**
     * Validate, sort, merge and bound a cut list.
     *
     * @param  array<int, array{start: mixed, end: mixed, type?: string}>  $cuts
     * @param  float|null  $sourceDuration  ffprobe's duration for the recording
     * @return list<array{type: string, start: float, end: float}>
     *
     * @throws ValidationException
     */
    public function normalize(array $cuts, ?float $sourceDuration): array
    {
        $ranges = [];

        foreach (array_values($cuts) as $index => $cut) {
            $start = round((float) ($cut['start'] ?? 0), 3);
            $end = round((float) ($cut['end'] ?? 0), 3);

            if ($start < 0) {
                $this->fail($index, 'A cut cannot start before the beginning of the recording.');
            }

            if ($end <= $start) {
                $this->fail($index, 'A cut must end after it starts.');
            }

            if ($end - $start < self::MIN_CUT_SECONDS) {
                $this->fail($index, 'That cut is too short to be meaningful.');
            }

            // Trust ffprobe's duration, as everywhere else in the pipeline.
            if ($sourceDuration !== null && $start >= $sourceDuration) {
                $this->fail($index, 'That cut starts after the recording ends.');
            }

            if ($sourceDuration !== null && $end > $sourceDuration + 0.001) {
                $this->fail($index, 'That cut extends past the end of the recording.');
            }

            $ranges[] = ['start' => $start, 'end' => min($end, $sourceDuration ?? $end)];
        }

        if ($ranges === []) {
            return [];
        }

        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $merged = [];

        foreach ($ranges as $range) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];

            if ($last === null) {
                $merged[] = $range;

                continue;
            }

            // Overlapping is a mistake worth reporting rather than silently
            // absorbing: it usually means the person mis-read a timestamp,
            // and quietly widening the cut removes audio they never chose to.
            if ($range['start'] < $last['end'] - self::MERGE_GAP_SECONDS) {
                throw ValidationException::withMessages([
                    'audio_edits' => ['Two removed sections overlap. Adjust or delete one of them.'],
                ]);
            }

            // Touching or a hair apart: one cut.
            if ($range['start'] <= $last['end'] + self::MERGE_GAP_SECONDS) {
                $merged[array_key_last($merged)]['end'] = max($last['end'], $range['end']);

                continue;
            }

            $merged[] = $range;
        }

        if ($sourceDuration !== null && $this->keptDuration($merged, $sourceDuration) < self::MIN_CUT_SECONDS) {
            throw ValidationException::withMessages([
                'audio_edits' => ['That would remove the whole recording.'],
            ]);
        }

        return array_map(
            static fn (array $r): array => ['type' => 'cut', 'start' => $r['start'], 'end' => $r['end']],
            $merged,
        );
    }

    /**
     * The spans that survive, in order — what the renderer concatenates.
     *
     * @param  list<array{type?: string, start: float, end: float}>  $cuts
     * @return list<array{start: float, end: float}>
     */
    public function keptSegments(array $cuts, float $sourceDuration): array
    {
        if ($cuts === []) {
            return [['start' => 0.0, 'end' => $sourceDuration]];
        }

        $segments = [];
        $cursor = 0.0;

        foreach ($cuts as $cut) {
            $start = (float) $cut['start'];
            $end = (float) $cut['end'];

            if ($start - $cursor > self::MIN_CUT_SECONDS) {
                $segments[] = ['start' => $cursor, 'end' => $start];
            }

            $cursor = max($cursor, $end);
        }

        if ($sourceDuration - $cursor > self::MIN_CUT_SECONDS) {
            $segments[] = ['start' => $cursor, 'end' => $sourceDuration];
        }

        return $segments;
    }

    /**
     * How long the render will actually be.
     *
     * Progress is a fraction of this, not of the original recording: a job
     * measured against an hour that only encodes fifty minutes reports 83%
     * and stops.
     *
     * @param  list<array{start: float, end: float}>  $cuts
     */
    public function keptDuration(array $cuts, float $sourceDuration): float
    {
        $removed = 0.0;

        foreach ($cuts as $cut) {
            $removed += max(0.0, min((float) $cut['end'], $sourceDuration) - (float) $cut['start']);
        }

        return round(max(0.0, $sourceDuration - $removed), 3);
    }

    /** @param list<array{start: float, end: float}> $cuts */
    public function removedDuration(array $cuts, float $sourceDuration): float
    {
        return round($sourceDuration - $this->keptDuration($cuts, $sourceDuration), 3);
    }

    /**
     * The filter chain that stitches the kept spans back together.
     *
     * atrim selects a span, asetpts restamps it to start at zero (without
     * which concat sees overlapping timestamps and produces silence or
     * garbage), and concat joins them. Every number here came through
     * normalize(); none of it is user text.
     *
     * Returns an empty string when there is nothing to cut, so the caller
     * keeps its existing single-input chain rather than paying for a
     * pointless one-segment concat.
     *
     * @param  list<array{start: float, end: float}>  $segments
     * @param  string  $inputLabel  e.g. "[1:a]"
     * @param  string  $outputLabel  e.g. "[acut]"
     */
    public function buildCutFilter(array $segments, string $inputLabel, string $outputLabel): string
    {
        if (count($segments) <= 1) {
            return '';
        }

        $parts = [];
        $labels = '';

        foreach ($segments as $i => $segment) {
            $label = "acut{$i}";
            $labels .= "[{$label}]";

            $parts[] = sprintf(
                '%satrim=start=%s:end=%s,asetpts=PTS-STARTPTS[%s]',
                $inputLabel,
                $this->seconds($segment['start']),
                $this->seconds($segment['end']),
                $label,
            );
        }

        $parts[] = sprintf('%sconcat=n=%d:v=0:a=1%s', $labels, count($segments), $outputLabel);

        return implode(';', $parts);
    }

    /**
     * Fixed-point, never scientific notation: FFmpeg reads "1.0E-5" as a
     * parse error, and a float cast can produce exactly that for a small
     * offset.
     */
    private function seconds(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function fail(int $index, string $message): never
    {
        throw ValidationException::withMessages([
            "audio_edits.{$index}" => [$message],
        ]);
    }
}
