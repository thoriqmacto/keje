<?php

namespace App\Services\Media;

use App\Exceptions\Media\RenderFailedException;
use Illuminate\Support\Facades\Storage;

/**
 * Single frames pulled out of a rendered video, for use as a thumbnail.
 *
 * Seeking before the input (`-ss` ahead of `-i`) makes FFmpeg jump straight to
 * the keyframe rather than decoding from the start, so a frame from the middle
 * of a ninety-minute lecture costs about as much as one from the first minute.
 * `-frames:v 1` then stops immediately: this is nothing like a transcode, and
 * generating three candidates is three cheap seeks rather than three passes.
 *
 * The timestamp is a validated number and every argument goes through Symfony
 * Process as an array element. Nothing here is interpolated into a shell.
 */
class VideoFrameExtractor
{
    public function __construct(
        private readonly FfmpegService $ffmpeg,
    ) {}

    /**
     * Sensible candidate timestamps for a video of this length.
     *
     * A quarter, a half and three-quarters of the way in. Not the first or
     * last frame: a lecture render opens and closes on near-static artwork,
     * and a thumbnail of the title card is what the video already looks like
     * in a list.
     *
     * @return list<float>
     */
    public function candidateTimestamps(float $duration): array
    {
        if ($duration <= 0) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (float $fraction): float => round($duration * $fraction, 3),
                [0.25, 0.5, 0.75],
            ),
            static fn (float $t): bool => $t > 0,
        ));
    }

    /**
     * Write one frame as a JPEG on the private disk.
     *
     * @param  string  $videoPath  absolute path to the rendered MP4
     * @param  string  $relativeTarget  path on the local disk, e.g. content/x/thumbs/a.jpg
     * @return string the relative path written
     *
     * @throws RenderFailedException
     */
    public function extract(string $videoPath, float $timestamp, string $relativeTarget): string
    {
        if (! is_file($videoPath)) {
            throw new RenderFailedException('The rendered video is no longer on this server.');
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($relativeTarget));

        $absolute = $disk->path($relativeTarget);

        $result = $this->ffmpeg->run([
            '-y',
            // Before -i: seek by keyframe instead of decoding up to the mark.
            '-ss', $this->seconds($timestamp),
            '-i', $videoPath,
            '-frames:v', '1',
            // YouTube wants a reasonably large still; the render is 720p, so
            // this is the frame as-is rather than an upscale.
            '-q:v', '3',
            $absolute,
        ]);

        if ($result['exit_code'] !== 0 || ! is_file($absolute)) {
            @unlink($absolute);

            throw new RenderFailedException('That frame could not be read from the video.');
        }

        return $relativeTarget;
    }

    /**
     * Fixed-point, never scientific notation — FFmpeg reads "1.0E-5" as a
     * parse error, and a float cast can produce exactly that.
     */
    private function seconds(float $value): string
    {
        return number_format(max(0, $value), 3, '.', '');
    }
}
