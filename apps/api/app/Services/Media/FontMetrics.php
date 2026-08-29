<?php

namespace App\Services\Media;

use RuntimeException;

/**
 * Measures TrueType text with GD + FreeType.
 *
 * GD's FreeType API takes a size in *points at 96 DPI*, while FFmpeg's
 * drawtext takes a size in *pixels*. One pixel is 0.75 points, so every
 * measurement scales the requested pixel size before asking GD. Getting this
 * wrong makes the browser preview and the rendered frame disagree by a third.
 */
class FontMetrics
{
    /** @var array<string, array{0:int,1:int,2:int}> */
    private array $cache = [];

    /** @param array<string, string> $fonts logical name => absolute .ttf path */
    public function __construct(
        private readonly array $fonts,
        private readonly float $pointScale = 0.75,
    ) {}

    /** Absolute path of a logical font name, verified to exist. */
    public function path(string $font): string
    {
        $path = $this->fonts[$font] ?? null;

        if ($path === null) {
            throw new RuntimeException("Unknown font '{$font}'. Configure it in config/media.php.");
        }

        if (! is_file($path)) {
            throw new RuntimeException("Font file is missing: {$path}");
        }

        return $path;
    }

    /**
     * Width, ascent and descent of a string at a given pixel size.
     *
     * @return array{width:int, ascent:int, descent:int, height:int}
     */
    public function measure(string $text, string $font, float $sizePx): array
    {
        $path = $this->path($font);
        $key = $path.'|'.$sizePx.'|'.$text;

        if (! isset($this->cache[$key])) {
            $box = imagettfbbox($sizePx * $this->pointScale, 0, $path, $text);

            if ($box === false) {
                throw new RuntimeException("Could not measure text with font: {$path}");
            }

            // imagettfbbox returns 4 corners relative to the origin/baseline:
            // [0,1] lower-left, [2,3] lower-right, [4,5] upper-right, [6,7] upper-left.
            // Negative y is above the baseline.
            $this->cache[$key] = [
                (int) ceil($box[2] - $box[0]),  // width
                (int) ceil(-$box[7]),           // ascent above baseline
                (int) ceil($box[1]),            // descent below baseline
            ];
        }

        [$width, $ascent, $descent] = $this->cache[$key];

        return [
            'width' => $width,
            'ascent' => $ascent,
            'descent' => $descent,
            'height' => $ascent + $descent,
        ];
    }

    public function width(string $text, string $font, float $sizePx): int
    {
        return $this->measure($text, $font, $sizePx)['width'];
    }

    /**
     * Ascent of the font itself rather than of a particular string, so that
     * lines with and without descenders still sit on the same baseline.
     */
    public function ascent(string $font, float $sizePx): int
    {
        return $this->measure('HÁQjgy', $font, $sizePx)['ascent'];
    }
}
