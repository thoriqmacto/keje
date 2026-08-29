<?php

namespace App\Services\Media;

use App\Exceptions\Media\TextDoesNotFitException;

/**
 * Turns a template definition plus a project's text into absolute, positioned
 * boxes.
 *
 * This is the single layout authority: the FFmpeg renderer draws what it
 * returns, and the API hands the very same structure to the browser preview,
 * so the two can never drift apart.
 *
 * Every element carries both `y` (top of the glyph box) and `baseline`. The
 * renderer positions with `y=<baseline>-ascent` so that differently-sized runs
 * — the muted "USTADZ" beside the bright speaker name — share a baseline.
 */
class TextLayoutService
{
    public function __construct(
        private readonly FontMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $content  topic, topic_sequence, speaker_name,
     *                                         primary_title, subtitle, part_number
     * @return array<string, mixed>
     *
     * @throws TextDoesNotFitException
     */
    public function resolve(array $template, array $content): array
    {
        $elements = $template['elements'];
        $resolved = [];

        // #1 Topic
        if (filled($content['topic'] ?? null)) {
            $resolved[] = $this->singleLine('topic', $elements['topic'], (string) $content['topic']);
        }

        // #2 Topic sequence — stored as an integer, decorated here.
        if (($content['topic_sequence'] ?? null) !== null) {
            $spec = $elements['topic_sequence'];
            $text = sprintf($spec['format'], (int) $content['topic_sequence']);
            $resolved[] = $this->singleLine('topic_sequence', $spec, $text);
        }

        // #3 + #4 Speaker line
        if (filled($content['speaker_name'] ?? null)) {
            foreach ($this->speakerLine($elements['speaker_line'], (string) $content['speaker_name']) as $item) {
                $resolved[] = $item;
            }
        }

        // #5 Branding — a template asset, never user input.
        $brand = $elements['branding'];
        $resolved[] = [
            'key' => 'branding',
            'element' => 'branding',
            'type' => 'image',
            'asset' => $brand['asset'],
            'x' => $brand['x'],
            'y' => $brand['y'],
            'width' => $brand['width'],
            'height' => $brand['height'],
        ];

        // #6 Primary title — exactly one line, or the render is refused.
        if (filled($content['primary_title'] ?? null)) {
            $resolved[] = $this->singleLine(
                'primary_title',
                $elements['primary_title'],
                (string) $content['primary_title'],
                'Primary title is too long for the '.$template['name'].' template. Please shorten it.',
            );
        }

        // #7 Subtitle — one or two balanced lines, never three.
        if (filled($content['subtitle'] ?? null)) {
            foreach ($this->wrappedLines($elements['subtitle'], (string) $content['subtitle'], $template['name']) as $item) {
                $resolved[] = $item;
            }
        }

        // #8 Part — omitted entirely when no part number is set.
        if (($content['part_number'] ?? null) !== null) {
            $spec = $elements['part'];
            $text = sprintf($spec['format'], (int) $content['part_number']);
            $resolved[] = $this->singleLine('part', $spec, $text);
        }

        $wave = $elements['waveform'];
        $resolved[] = [
            'key' => 'waveform',
            'element' => 'waveform',
            'type' => 'waveform',
            'x' => $wave['x'],
            'y' => $wave['y'],
            'width' => $wave['width'],
            'height' => $wave['height'],
            'color' => $wave['color'],
            'mode' => $wave['mode'],
        ];

        return [
            'template_key' => $template['key'],
            'template_name' => $template['name'],
            'canvas' => $template['canvas'],
            'safe_margin' => $template['safe_margin'],
            'background' => $template['background'],
            'elements' => $resolved,
        ];
    }

    /**
     * Lay out a run that must occupy exactly one line, shrinking the font
     * until it fits and refusing rather than cropping.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function singleLine(string $key, array $spec, string $raw, ?string $failureMessage = null): array
    {
        $text = $this->transform($raw, $spec['transform'] ?? 'none');
        $font = $spec['font'];
        $min = (float) ($spec['min_font_size'] ?? $spec['font_size']);

        $size = $this->fitToWidth($text, $font, (float) $spec['font_size'], $min, (int) $spec['width']);

        if ($size === null) {
            throw new TextDoesNotFitException(
                $key,
                $failureMessage ?? "The {$key} text is too long for this template. Please shorten it.",
            );
        }

        return $this->textBox($key, $key, $spec, $text, $font, $size, (int) $spec['y']);
    }

    /**
     * Lay out the subtitle: one line if it fits, otherwise the most balanced
     * two-line split. Never three lines, never a mid-word break.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function wrappedLines(array $spec, string $raw, string $templateName): array
    {
        $text = $this->transform($raw, $spec['transform'] ?? 'none');
        $font = $spec['font'];
        $maxWidth = (int) $spec['width'];
        $maxLines = (int) ($spec['max_lines'] ?? 2);
        $min = (float) ($spec['min_font_size'] ?? $spec['font_size']);

        $lines = null;
        $chosen = null;

        for ($size = (float) $spec['font_size']; $size >= $min; $size -= 1.0) {
            if ($this->metrics->width($text, $font, $size) <= $maxWidth) {
                $lines = [$text];
                $chosen = $size;
                break;
            }

            if ($maxLines >= 2) {
                $split = $this->balancedSplit($text, $font, $size, $maxWidth);

                if ($split !== null) {
                    $lines = $split;
                    $chosen = $size;
                    break;
                }
            }
        }

        if ($lines === null || $chosen === null) {
            throw new TextDoesNotFitException(
                'subtitle',
                "Supporting subtitle does not fit in {$maxLines} lines for the {$templateName} "
                .'template. Please shorten it.',
            );
        }

        // Baseline of line 1 is derived from its own glyphs so the box top
        // lands exactly on the template's y; later baselines step by line height.
        $lineHeight = (int) round($chosen * (float) ($spec['line_height'] ?? 1.2));
        $firstBaseline = (int) $spec['y'] + $this->metrics->measure($lines[0], $font, $chosen)['ascent'];

        $out = [];

        foreach ($lines as $i => $line) {
            $baseline = $firstBaseline + ($i * $lineHeight);
            $ascent = $this->metrics->measure($line, $font, $chosen)['ascent'];

            $out[] = $this->textBox(
                'subtitle_line_'.($i + 1),
                'subtitle',
                $spec,
                $line,
                $font,
                $chosen,
                $baseline - $ascent,
                $baseline,
            );
        }

        return $out;
    }

    /**
     * The centred "USTADZ  SPEAKER NAME" pair, sized as a unit and sharing a
     * baseline. The label is a template constant; only the name can overflow,
     * so only the name shrinks.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function speakerLine(array $spec, string $speakerName): array
    {
        $labelSpec = $spec['parts']['label'];
        $nameSpec = $spec['parts']['name'];

        $label = $this->transform($labelSpec['text'], $labelSpec['transform'] ?? 'none');
        $name = $this->transform($speakerName, $nameSpec['transform'] ?? 'none');

        $gap = (int) $spec['gap'];
        $maxWidth = (int) $spec['max_width'];
        $labelSize = (float) $labelSpec['font_size'];
        $labelWidth = $this->metrics->width($label, $labelSpec['font'], $labelSize);

        $nameSize = (float) $nameSpec['font_size'];
        $nameMin = (float) ($nameSpec['min_font_size'] ?? $nameSize);
        $budget = $maxWidth - $labelWidth - $gap;

        $fitted = $this->fitToWidth($name, $nameSpec['font'], $nameSize, $nameMin, max($budget, 1));
        // A speaker name is a proper noun we must not refuse a render over —
        // fall back to the minimum size rather than throwing.
        $nameSize = $fitted ?? $nameMin;
        $nameWidth = $this->metrics->width($name, $nameSpec['font'], $nameSize);

        $total = $labelWidth + $gap + $nameWidth;
        $startX = (int) round($spec['center_x'] - ($total / 2));
        $baseline = (int) $spec['baseline_y'];

        return [
            [
                'key' => 'speaker_label',
                'element' => 'speaker_line',
                'type' => 'text',
                'text' => $label,
                'x' => $startX,
                'y' => $baseline - $this->metrics->measure($label, $labelSpec['font'], $labelSize)['ascent'],
                'baseline' => $baseline,
                'width' => $labelWidth,
                'text_width' => $labelWidth,
                'align' => 'left',
                'font' => $labelSpec['font'],
                'font_size' => (int) round($labelSize),
                'color' => $labelSpec['color'],
            ],
            [
                'key' => 'speaker_name',
                'element' => 'speaker_line',
                'type' => 'text',
                'text' => $name,
                'x' => $startX + $labelWidth + $gap,
                'y' => $baseline - $this->metrics->measure($name, $nameSpec['font'], $nameSize)['ascent'],
                'baseline' => $baseline,
                'width' => $nameWidth,
                'text_width' => $nameWidth,
                'align' => 'left',
                'font' => $nameSpec['font'],
                'font_size' => (int) round($nameSize),
                'color' => $nameSpec['color'],
            ],
        ];
    }

    /**
     * Largest size in [min, preferred] whose rendered width fits, or null.
     */
    private function fitToWidth(string $text, string $font, float $preferred, float $min, int $maxWidth): ?float
    {
        for ($size = $preferred; $size >= $min; $size -= 1.0) {
            if ($this->metrics->width($text, $font, $size) <= $maxWidth) {
                return $size;
            }
        }

        return null;
    }

    /**
     * Split on a word boundary into the two lines whose widths are closest to
     * equal — deliberately not a greedy fill, which would leave a long first
     * line above a stub. Null when no split fits at this size.
     *
     * @return array{0:string,1:string}|null
     */
    private function balancedSplit(string $text, string $font, float $size, int $maxWidth): ?array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < 2) {
            return null;
        }

        $best = null;
        $bestScore = PHP_INT_MAX;

        for ($i = 1; $i < count($words); $i++) {
            $first = implode(' ', array_slice($words, 0, $i));
            $second = implode(' ', array_slice($words, $i));

            $w1 = $this->metrics->width($first, $font, $size);
            $w2 = $this->metrics->width($second, $font, $size);

            if ($w1 > $maxWidth || $w2 > $maxWidth) {
                continue;
            }

            $score = abs($w1 - $w2);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = [$first, $second];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function textBox(
        string $key,
        string $element,
        array $spec,
        string $text,
        string $font,
        float $size,
        int $top,
        ?int $baseline = null,
    ): array {
        $measured = $this->metrics->measure($text, $font, $size);

        return [
            'key' => $key,
            'element' => $element,
            'type' => 'text',
            'text' => $text,
            'x' => (int) $spec['x'],
            'y' => $top,
            'baseline' => $baseline ?? ($top + $measured['ascent']),
            'width' => (int) $spec['width'],
            'text_width' => $measured['width'],
            'align' => $spec['align'] ?? 'left',
            'font' => $font,
            'font_size' => (int) round($size),
            'color' => $spec['color'],
        ];
    }

    private function transform(string $text, string $transform): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $transform === 'upper' ? mb_strtoupper($text, 'UTF-8') : $text;
    }
}
