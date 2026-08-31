<?php

namespace App\Services\Media;

use App\Models\ContentProject;

/**
 * A hash of everything that changes what the rendered MP4 looks like.
 *
 * Projects are editable now, which creates a correctness problem: a finished
 * MP4 was produced from inputs that may since have changed, and presenting it
 * as the project's current output is a lie. Comparing the project's fingerprint
 * against the one stored by the render that produced the file answers "is this
 * output still current" in one place.
 *
 * One place, deliberately. The alternative is a scatter of
 * `if ($project->isDirty('subtitle')) { $project->render_status = ... }`
 * checks, which is exactly the kind of thing that goes stale the moment
 * somebody adds a field and forgets one of them. Adding a render input here is
 * a single edit, and forgetting to add one shows up as an output that fails to
 * go stale rather than as a silent mystery.
 *
 * What is deliberately NOT included: the working title (a label for humans,
 * never drawn), publishing metadata (titles, descriptions, privacy — YouTube's
 * business, not FFmpeg's), and anything about Drive or YouTube state. Renaming
 * a project must not invalidate a two-hour encode.
 */
class RenderInputFingerprint
{
    /**
     * @return string 64-char sha256 of the render-affecting inputs
     */
    public function for(ContentProject $project): string
    {
        return hash('sha256', json_encode($this->inputs($project), JSON_THROW_ON_ERROR));
    }

    /**
     * The inputs themselves, exposed so a diagnostic can show what changed
     * rather than only that something did.
     *
     * @return array<string, mixed>
     */
    public function inputs(ContentProject $project): array
    {
        return [
            // Source media identity. The path alone is not enough: re-uploading
            // a different recording reuses the same server-controlled filename,
            // so size and duration are what actually distinguish the bytes.
            'audio' => [
                'path' => $project->source_audio_path,
                'size' => $project->source_audio_size,
                'duration' => $this->number($project->source_audio_duration),
            ],
            'background' => [
                'path' => $project->background_image_path,
                'size' => $project->background_image_size,
                'width' => $project->background_image_width,
                'height' => $project->background_image_height,
            ],

            // Everything drawn on the frame.
            'topic' => $project->topic?->name,
            'topic_sequence' => $project->topic_sequence,
            'speaker' => $project->speaker?->renderName(),
            'primary_title' => $project->primary_title,
            'subtitle' => $project->subtitle,
            'part_number' => $project->part_number,

            'template_key' => $project->template_key,
            'render_settings' => $this->normalize($project->render_settings),

            // Cutting five seconds out of the recording changes every frame
            // after the cut, so the edit list is as much a render input as the
            // audio file itself.
            'audio_edits' => $this->normalize($project->audio_edits),
        ];
    }

    /** True when the stored output no longer represents the current inputs. */
    public function isStale(ContentProject $project): bool
    {
        // Nothing rendered yet, or rendered before fingerprints existed: not
        // stale, just unknown. Claiming staleness for every historical project
        // on the day this ships would be noise, not information.
        if (blank($project->last_render_input_hash) || blank($project->output_path)) {
            return false;
        }

        return $this->for($project) !== $project->last_render_input_hash;
    }

    /**
     * Sort keys so an equivalent array in a different order is the same
     * fingerprint. A JSON column round-trips key order unpredictably, and
     * "the user re-saved the same settings" must not invalidate a render.
     *
     * @param  array<mixed>|null  $value
     * @return array<mixed>|null
     */
    private function normalize(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }

    /**
     * Floats round-trip through JSON and the database with enough noise that
     * an untouched duration can differ in the last bits. Milliseconds are far
     * finer than anything visible in a render.
     */
    private function number(int|float|null $value): ?float
    {
        return $value === null ? null : round((float) $value, 3);
    }
}
