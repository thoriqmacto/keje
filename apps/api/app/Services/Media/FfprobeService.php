<?php

namespace App\Services\Media;

use App\Exceptions\Media\MediaToolUnavailableException;
use App\Exceptions\Media\UnusableMediaException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Structural inspection of uploaded media.
 *
 * Extensions and browser-supplied MIME types are claims, not evidence — this
 * is what actually decides whether a file is usable. Arguments are passed as
 * an array to Symfony Process, so no filename ever reaches a shell.
 */
class FfprobeService
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeout = 30,
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

        $process = new Process([$this->binary, '-version'], timeout: $this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $first = strtok($process->getOutput(), "\n");

        return $first === false ? null : trim($first);
    }

    /**
     * Full ffprobe JSON for a file.
     *
     * @return array<string, mixed>
     *
     * @throws UnusableMediaException
     * @throws MediaToolUnavailableException
     */
    public function inspect(string $path): array
    {
        // Checked before the file is blamed: without ffprobe every upload
        // looks corrupt, and reporting that as a validation error sends
        // people off to re-encode a recording that was never the problem.
        if (! $this->isAvailable()) {
            throw MediaToolUnavailableException::at($this->binary);
        }

        if (! is_file($path)) {
            throw new UnusableMediaException('The uploaded file could not be read.');
        }

        $process = new Process([
            $this->binary,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ], timeout: $this->timeout);

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            throw new UnusableMediaException(
                'The file could not be read as media. Please upload a valid audio file.',
            );
        }

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data)) {
            throw new UnusableMediaException('The media could not be inspected.');
        }

        return $data;
    }

    /**
     * Inspect a file and return its first usable audio stream.
     *
     * Handles MPEG containers that carry both video and audio: the first
     * audio stream wins and the video track is ignored.
     *
     * @return array{codec:string, duration:float, sample_rate:?int, channels:?int, bitrate:?int}
     *
     * @throws UnusableMediaException
     */
    public function inspectAudio(string $path): array
    {
        $data = $this->inspect($path);
        $streams = $data['streams'] ?? [];

        $audio = null;

        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) === 'audio') {
                $audio = $stream;
                break;
            }
        }

        if ($audio === null) {
            throw new UnusableMediaException(
                'That file contains no audio track. Please upload a lecture recording with audio.',
            );
        }

        // Stream duration is missing in some containers; the format-level
        // duration is the reliable fallback.
        $duration = (float) ($audio['duration'] ?? $data['format']['duration'] ?? 0.0);

        if ($duration <= 0.0) {
            throw new UnusableMediaException(
                'The audio duration could not be determined. The file may be incomplete or corrupt.',
            );
        }

        $bitrate = $audio['bit_rate'] ?? $data['format']['bit_rate'] ?? null;

        return [
            'codec' => (string) ($audio['codec_name'] ?? 'unknown'),
            'duration' => $duration,
            'sample_rate' => isset($audio['sample_rate']) ? (int) $audio['sample_rate'] : null,
            'channels' => isset($audio['channels']) ? (int) $audio['channels'] : null,
            'bitrate' => $bitrate !== null ? (int) $bitrate : null,
        ];
    }

    /**
     * Verify a file really is a decodable image and report its dimensions.
     *
     * @return array{width:int, height:int, codec:string}
     *
     * @throws UnusableMediaException
     */
    public function inspectImage(string $path): array
    {
        $data = $this->inspect($path);

        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }

            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);

            if ($width > 0 && $height > 0) {
                return [
                    'width' => $width,
                    'height' => $height,
                    'codec' => (string) ($stream['codec_name'] ?? 'unknown'),
                ];
            }
        }

        throw new UnusableMediaException(
            'That file could not be read as an image. Please upload a JPG, PNG or WebP.',
        );
    }
}
