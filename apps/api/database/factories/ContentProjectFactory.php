<?php

namespace Database\Factories;

use App\Enums\RenderStatus;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\ContentProject>
 */
class ContentProjectFactory extends Factory
{
    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(4);

        return [
            'user_id' => User::factory(),
            'topic_id' => null,
            'topic_sequence' => null,
            'speaker_id' => null,
            'working_title' => rtrim($title, '.'),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'template_key' => 'kajian-tematik',
            'primary_title' => 'Keutamaan Lapar, Hidup',
            'subtitle' => 'Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu',
            'part_number' => 3,
        ];
    }

    /** Attach a topic and speaker owned by the same user. */
    public function grouped(): static
    {
        return $this->afterCreating(function ($project): void {
            $project->forceFill([
                'topic_id' => ContentTopic::factory()->create(['user_id' => $project->user_id])->id,
                'topic_sequence' => 11,
                'speaker_id' => Speaker::factory()->create(['user_id' => $project->user_id])->id,
            ])->save();
        });
    }

    /** Source audio and background present, so the project is renderable. */
    public function withMedia(): static
    {
        return $this->state(fn () => [
            'source_audio_path' => 'content/fixture/source/audio.mp3',
            'source_audio_original_name' => 'lecture.mp3',
            'source_audio_mime' => 'audio/mpeg',
            'source_audio_size' => 4_200_000,
            'source_audio_duration' => 1800.0,
            'source_audio_codec' => 'mp3',
            'source_audio_sample_rate' => 44100,
            'source_audio_channels' => 2,
            'background_image_path' => 'content/fixture/source/background.jpg',
            'background_image_original_name' => 'bg.jpg',
            'background_image_mime' => 'image/jpeg',
            'background_image_size' => 900_000,
            'background_image_width' => 1920,
            'background_image_height' => 1080,
            'render_status' => RenderStatus::MediaReady,
        ]);
    }

    /**
     * withMedia, plus the files actually on the private disk.
     *
     * The columns alone are not enough for anything that dispatches a render:
     * the endpoint refuses to queue a project whose recorded files are gone,
     * because that is exactly the state a deploy can leave behind. Fake the
     * disk before using this.
     */
    public function withMediaFiles(): static
    {
        return $this->withMedia()->afterCreating(function (ContentProject $project): void {
            // A caller may have overridden either path to null to build the
            // "never uploaded" case; only write the ones that exist.
            $files = [
                $project->source_audio_path => 'fake-audio',
                $project->background_image_path => 'fake-image',
            ];

            foreach (array_filter($files, 'filled', ARRAY_FILTER_USE_KEY) as $path => $contents) {
                Storage::disk('local')->put($path, $contents);
            }
        });
    }
}
