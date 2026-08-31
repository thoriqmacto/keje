<?php

namespace App\Services\Media;

use App\Exceptions\Media\RenderFailedException;
use App\Models\ContentProject;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds and executes the FFmpeg render for a ContentProject.
 *
 * Two security rules shape this class:
 *
 *  1. User text never reaches argv or the filter graph. Each run is written to
 *     its own .txt file and drawtext reads it with `textfile=`, with
 *     `expansion=none` so a title containing `%{pts}` stays literal text.
 *  2. The filter graph is assembled solely from the template definition and
 *     application config. There is no path by which a request can inject a
 *     filter.
 */
class VideoRenderer
{
    /** Text runs are written here, one file per drawn line. */
    private const TEXT_FILES = [
        'topic' => 'topic.txt',
        'topic_sequence' => 'topic-sequence.txt',
        'speaker_label' => 'speaker-label.txt',
        'speaker_name' => 'speaker-name.txt',
        'primary_title' => 'primary-title.txt',
        'subtitle_line_1' => 'subtitle-line-1.txt',
        'subtitle_line_2' => 'subtitle-line-2.txt',
        'part' => 'part.txt',
    ];

    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly TextLayoutService $layout,
        private readonly FontMetrics $fonts,
        private readonly FfmpegService $ffmpeg,
        // Appended rather than prepended: the render tests construct this
        // positionally, and the container resolves either way.
        private readonly AudioEditService $audioEdits,
    ) {}

    /**
     * Resolve the layout for a project without rendering — used by the API to
     * drive the browser preview and to reject unfittable text up front.
     *
     * @return array<string, mixed>
     */
    public function resolveLayout(ContentProject $project): array
    {
        $template = $this->templates->get($project->template_key);

        return $this->layout->resolve($template, [
            'topic' => $project->topic?->name,
            'topic_sequence' => $project->topic_sequence,
            'speaker_name' => $project->speaker?->renderName(),
            'primary_title' => $project->primary_title,
            'subtitle' => $project->subtitle,
            'part_number' => $project->part_number,
        ]);
    }

    /**
     * Render the project to MP4.
     *
     * Writes to temp/ and only moves the file into renders/ once FFmpeg has
     * exited cleanly, so a partial file is never served or backed up.
     *
     * @param  Closure(float):void|null  $onProgress  receives 0..1
     * @return array{output_path:string, size:int, duration:float, exit_code:int, log:string}
     *
     * @throws RenderFailedException
     */
    public function render(ContentProject $project, ?Closure $onProgress = null): array
    {
        $disk = Storage::disk('local');

        if (! $project->hasRequiredMedia()) {
            throw new RenderFailedException('Both a source audio file and a background image are required.');
        }

        $audioPath = $disk->path($project->source_audio_path);
        $backgroundPath = $disk->path($project->background_image_path);

        $sources = [
            'audio' => [$project->source_audio_path, $audioPath],
            'background image' => [$project->background_image_path, $backgroundPath],
        ];

        foreach ($sources as $label => [$relative, $path]) {
            if (! is_file($path)) {
                // Naming the path it looked for is the difference between a
                // dead end and a two-minute fix: the database and the disk
                // disagree, and only the path says where to look. The
                // relative path is the app's own, not a server detail — the
                // absolute one goes to the log.
                Log::warning('Render source missing from storage', [
                    'project_id' => $project->id,
                    'absolute_path' => $path,
                ]);

                throw new RenderFailedException(
                    "The source {$label} is missing from storage (expected {$relative}). "
                    .'Re-upload it, then render again.',
                );
            }
        }

        $layout = $this->resolveLayout($project);

        $dir = $project->storageDirectory();
        $disk->makeDirectory("{$dir}/text");
        $disk->makeDirectory("{$dir}/renders");
        $disk->makeDirectory("{$dir}/temp");

        $textPaths = $this->writeTextFiles($disk->path("{$dir}/text"), $layout);

        $tempOutput = $disk->path("{$dir}/temp/output.mp4");
        $finalOutput = "{$dir}/renders/output.mp4";

        // Removed sections are decisions, not edits: the source MP3 is never
        // rewritten, so the cut list is applied here, at encode time.
        $sourceDuration = (float) $project->source_audio_duration;
        $cuts = (array) ($project->audio_edits ?? []);
        $segments = $this->audioEdits->keptSegments($cuts, $sourceDuration);
        $effectiveDuration = $this->audioEdits->keptDuration($cuts, $sourceDuration);

        $arguments = $this->buildArguments(
            layout: $layout,
            audioPath: $audioPath,
            backgroundPath: $backgroundPath,
            textPaths: $textPaths,
            outputPath: $tempOutput,
            duration: $effectiveDuration,
            renderSettings: (array) ($project->render_settings ?? []),
            audioSegments: $segments,
        );

        $result = $this->ffmpeg->run(
            arguments: $arguments,
            // Progress is a fraction of what will actually be encoded. A job
            // measured against the original recording would report 92% and
            // stop when five minutes have been cut out of it.
            totalDuration: $effectiveDuration,
            onProgress: $onProgress,
        );

        if ($result['exit_code'] !== 0 || ! is_file($tempOutput)) {
            @unlink($tempOutput);

            throw new RenderFailedException(
                $this->explainFailure($result['log']),
            );
        }

        $disk->delete($finalOutput);
        $disk->move("{$dir}/temp/output.mp4", $finalOutput);

        return [
            'output_path' => $finalOutput,
            'size' => (int) $disk->size($finalOutput),
            'duration' => $effectiveDuration,
            'exit_code' => $result['exit_code'],
            'log' => $result['log'],
        ];
    }

    /**
     * Assemble the full FFmpeg argument list.
     *
     * Kept public and side-effect free so tests can assert on the graph —
     * that the waveform exists, that every template layer is present — without
     * executing FFmpeg.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, string>  $textPaths  layout key => .txt path
     * @param  float|null  $duration  audio duration from ffprobe; bounds the output
     * @param  array<string, mixed>  $renderSettings
     * @return list<string>
     */
    public function buildArguments(
        array $layout,
        string $audioPath,
        string $backgroundPath,
        array $textPaths,
        string $outputPath,
        ?float $duration = null,
        array $audioSegments = [],
        array $renderSettings = [],
    ): array {
        $video = config('media.video');
        $encoding = config('media.encoding');
        $fps = (int) $video['fps'];
        $width = (int) $layout['canvas']['width'];
        $height = (int) $layout['canvas']['height'];

        $inputs = [];
        $filters = [];

        // ── Inputs ──────────────────────────────────────────────────────────
        // 0: background still, looped for the length of the audio.
        $inputs[] = ['-loop', '1', '-framerate', (string) $fps, '-i', $backgroundPath];
        // 1: the lecture recording — decoded, never stream-copied.
        $inputs[] = ['-i', $audioPath];

        $next = 2;
        $overlayIndex = null;
        $brandingIndex = null;

        $overlayEnabled = (bool) ($layout['background']['overlay']['enabled'] ?? false);
        $overlayAsset = $this->templates->assetPath($layout['template_key'], 'overlay.png');

        if ($overlayEnabled && is_file($overlayAsset)) {
            $overlayIndex = $next++;
            $inputs[] = ['-loop', '1', '-framerate', (string) $fps, '-i', $overlayAsset];
        }

        $branding = $this->findElement($layout, 'branding');

        if ($branding !== null) {
            $asset = $this->templates->assetPath($layout['template_key'], $branding['asset']);

            if (is_file($asset)) {
                $brandingIndex = $next++;
                $inputs[] = ['-loop', '1', '-framerate', (string) $fps, '-i', $asset];
            }
        }

        // ── Video chain ─────────────────────────────────────────────────────
        // cover + centre-crop: fill the canvas, preserve aspect, never stretch.
        $filters[] = sprintf(
            '[0:v]scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1,format=rgba[bg]',
            $width, $height, $width, $height,
        );
        $stage = 'bg';

        // Non-destructive readability gradient, built from the same stops the
        // preview uses. Only exists during the render.
        if ($overlayIndex !== null) {
            $filters[] = sprintf('[%d:v]scale=%d:%d[ovl]', $overlayIndex, $width, $height);
            $filters[] = "[{$stage}][ovl]overlay=0:0[bgo]";
            $stage = 'bgo';
        }

        // #5 Branding.
        if ($brandingIndex !== null && $branding !== null) {
            $filters[] = sprintf(
                '[%d:v]scale=%d:%d[brand]',
                $brandingIndex, (int) $branding['width'], (int) $branding['height'],
            );
            $filters[] = sprintf(
                '[%s][brand]overlay=%d:%d[bgb]',
                $stage, (int) $branding['x'], (int) $branding['y'],
            );
            $stage = 'bgb';
        }

        // #1 #2 #3 #4 #6 #7 #8 — one drawtext per resolved run.
        $drawn = 0;

        foreach ($layout['elements'] as $element) {
            if ($element['type'] !== 'text') {
                continue;
            }

            $path = $textPaths[$element['key']] ?? null;

            if ($path === null) {
                continue;
            }

            $label = 'txt'.$drawn++;
            $filters[] = "[{$stage}]".$this->drawText($element, $path)."[{$label}]";
            $stage = $label;
        }

        // ── Audio chain ─────────────────────────────────────────────────────
        // Normalise the sample rate and channel layout, then split: one branch
        // becomes the output track, the other drives the waveform.
        $audioFilters = [
            sprintf('aresample=%d', (int) $encoding['audio_sample_rate']),
        ];

        // EBU R128 loudness. Off unless the project explicitly opts in — we do
        // not materially alter lecture audio without intent.
        if ((bool) ($renderSettings['loudnorm'] ?? config('media.loudnorm.enabled'))) {
            $audioFilters[] = sprintf(
                'loudnorm=I=%s:TP=%s:LRA=%s',
                config('media.loudnorm.i'),
                config('media.loudnorm.tp'),
                config('media.loudnorm.lra'),
            );
        }

        // Cut before everything else, so loudness and the waveform both see
        // the edited audio. A waveform drawn from the removed content would
        // not match what is heard.
        $cutFilter = $this->audioEdits->buildCutFilter($audioSegments, '[1:a]', '[acut]');
        $audioSource = '[1:a]';

        if ($cutFilter !== '') {
            $filters[] = $cutFilter;
            $audioSource = '[acut]';
        }

        $filters[] = $audioSource.implode(',', $audioFilters).',asplit=2[aout][awave]';

        // Waveform. showwaves already emits transparent RGBA, so it composites
        // straight over the frame with no colour keying.
        $wave = $this->findElement($layout, 'waveform');

        if ($wave !== null) {
            $filters[] = sprintf(
                '[awave]showwaves=s=%dx%d:mode=%s:colors=%s:rate=%d[wave]',
                (int) $wave['width'], (int) $wave['height'],
                $wave['mode'], $wave['color'], $fps,
            );
            // shortest=1 makes the composite end when the (finite) waveform
            // ends. Without it the looped stills feed the graph forever and
            // the encode never terminates.
            $filters[] = sprintf(
                '[%s][wave]overlay=%d:%d:shortest=1[vmix]',
                $stage, (int) $wave['x'], (int) $wave['y'],
            );
            $stage = 'vmix';
        } else {
            // Keep the audio graph valid even without a waveform layer.
            $filters[] = '[awave]anullsink';
        }

        $filters[] = sprintf('[%s]format=%s[vout]', $stage, $encoding['pixel_format']);

        // ── Assemble ────────────────────────────────────────────────────────
        $args = ['-y', '-hide_banner', '-nostdin'];

        foreach ($inputs as $input) {
            array_push($args, ...$input);
        }

        array_push(
            $args,
            '-filter_complex', implode(';', $filters),
            '-map', '[vout]',
            '-map', '[aout]',
            '-c:v', (string) $encoding['video_codec'],
            '-profile:v', (string) $encoding['profile'],
            '-pix_fmt', (string) $encoding['pixel_format'],
            '-r', (string) $fps,
            '-crf', (string) $encoding['crf'],
            '-preset', (string) $encoding['preset'],
            '-c:a', (string) $encoding['audio_codec'],
            '-ar', (string) $encoding['audio_sample_rate'],
            '-b:a', (string) $encoding['audio_bitrate'],
        );

        if ($encoding['faststart']) {
            array_push($args, '-movflags', '+faststart');
        }

        // Hard duration bound. `-shortest` alone is not enough here: the
        // background, overlay and branding are `-loop 1` stills, so the video
        // branch of the graph is infinite and a stall in the audio branch
        // would encode forever. ffprobe already told us exactly how long the
        // lecture is, so state it.
        if ($duration !== null && $duration > 0) {
            array_push($args, '-t', sprintf('%.3f', $duration));
        }

        array_push(
            $args,
            // Ends the video with the audio rather than looping the still forever.
            '-shortest',
            // Machine-readable progress on stdout; the human log stays on stderr.
            '-progress', 'pipe:1',
            '-nostats',
            $outputPath,
        );

        return $args;
    }

    /**
     * One drawtext filter for a resolved run.
     *
     * `expansion=none` is load-bearing: without it drawtext would interpret
     * `%{...}` sequences inside a user's title as directives.
     *
     * @param  array<string, mixed>  $element
     */
    private function drawText(array $element, string $textPath): string
    {
        $x = $element['align'] === 'center'
            ? sprintf('(%d+(%d-text_w)/2)', (int) $element['x'], (int) $element['width'])
            : (string) (int) $element['x'];

        // Position by baseline so runs of different sizes align on one line.
        $y = sprintf('(%d-ascent)', (int) $element['baseline']);

        return sprintf(
            'drawtext=fontfile=%s:textfile=%s:expansion=none:fontsize=%d:fontcolor=%s:x=%s:y=%s',
            $this->escape($this->fonts->path($element['font'])),
            $this->escape($textPath),
            (int) $element['font_size'],
            $this->escape($this->toFfmpegColor($element['color'])),
            $x,
            $y,
        );
    }

    /**
     * Write one text file per drawn run.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, string> layout key => absolute path
     */
    private function writeTextFiles(string $textDir, array $layout): array
    {
        $paths = [];

        // Clear stale runs so a subtitle that shrank from two lines to one
        // cannot leave the old second line behind.
        foreach (self::TEXT_FILES as $file) {
            @unlink($textDir.'/'.$file);
        }

        foreach ($layout['elements'] as $element) {
            if ($element['type'] !== 'text') {
                continue;
            }

            $file = self::TEXT_FILES[$element['key']] ?? null;

            if ($file === null) {
                continue;
            }

            $path = $textDir.'/'.$file;

            // No trailing newline: drawtext would render it as a second line.
            if (file_put_contents($path, $element['text']) === false) {
                throw new RenderFailedException('Could not stage text for rendering.');
            }

            $paths[$element['key']] = $path;
        }

        return $paths;
    }

    /** @param array<string, mixed> $layout */
    private function findElement(array $layout, string $key): ?array
    {
        foreach ($layout['elements'] as $element) {
            if ($element['key'] === $key) {
                return $element;
            }
        }

        return null;
    }

    /** `#RRGGBB` from the template becomes FFmpeg's `0xRRGGBB`. */
    private function toFfmpegColor(string $color): string
    {
        return str_starts_with($color, '#') ? '0x'.substr($color, 1) : $color;
    }

    /**
     * Escape a value for inclusion in a filter description. Only ever applied
     * to server-controlled strings (font and text-file paths, template colours).
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ':', "'", '[', ']', ',', ';'],
            ['\\\\', '\\:', "\\'", '\\[', '\\]', '\\,', '\\;'],
            $value,
        );
    }

    /**
     * Turn FFmpeg's log tail into something worth showing a user, without
     * leaking server paths. The full log stays on the RenderJob.
     */
    private function explainFailure(string $log): string
    {
        $patterns = [
            'No space left on device' => 'The server ran out of disk space while rendering.',
            'Invalid data found' => 'The source audio could not be decoded. Please re-upload the recording.',
            'Cannot find a valid font' => 'The render font is not installed on the server.',
            'Could not open file' => 'A source file could not be read during rendering.',
        ];

        foreach ($patterns as $needle => $message) {
            if (str_contains($log, $needle)) {
                return $message;
            }
        }

        return 'Rendering failed. The technical details have been recorded for troubleshooting.';
    }
}
