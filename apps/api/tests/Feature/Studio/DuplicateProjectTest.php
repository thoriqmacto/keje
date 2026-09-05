<?php

namespace Tests\Feature\Studio;

use App\Enums\DriveStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Starting a project from an existing one.
 *
 * The rule these tests exist to hold: copy what describes the *series*, copy
 * nothing that describes *one recording*. Getting the first half wrong costs
 * some retyping. Getting the second half wrong is much worse — a duplicate
 * that inherited a video id would let the studio believe a brand new project
 * was already on YouTube, and every "replace this video" path would then be
 * pointing at somebody else's published lecture.
 */
class DuplicateProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function duplicate(ContentProject $project): array
    {
        return $this->postJson("/api/v1/content-projects/{$project->uuid}/duplicate")
            ->assertCreated()
            ->json('data');
    }

    #[Test]
    public function it_carries_over_the_grouping_and_the_youtube_fields(): void
    {
        $topic = ContentTopic::factory()->for($this->user)->create(['name' => 'Aqidah']);
        $speaker = Speaker::factory()->for($this->user)->create(['name' => 'Ustadz Fulan']);

        $original = ContentProject::factory()->for($this->user)->create([
            'working_title' => 'Kajian Tematik #11',
            'topic_id' => $topic->id,
            'speaker_id' => $speaker->id,
            'primary_title' => 'Keutamaan Lapar',
            'subtitle' => 'Bagian pertama',
            'part_number' => 3,
            'render_settings' => ['loudnorm' => true],
            'youtube_metadata' => [
                'title' => 'Keutamaan Lapar | Aqidah',
                'description' => 'Kajian rutin pekanan.',
                'tags' => ['kajian', 'aqidah'],
                'category_id' => '27',
                'default_language' => 'id',
                'privacy_status' => 'unlisted',
                'playlist_id' => 'PL123',
            ],
        ]);

        $copy = $this->duplicate($original);

        $this->assertSame($topic->uuid, $copy['topic']['id']);
        $this->assertSame($speaker->uuid, $copy['speaker']['id']);
        $this->assertSame('Keutamaan Lapar', $copy['primary_title']);
        $this->assertSame('Bagian pertama', $copy['subtitle']);
        $this->assertSame(3, $copy['part_number']);
        $this->assertSame(['loudnorm' => true], $copy['render_settings'] ?? null);

        $this->assertSame('Keutamaan Lapar | Aqidah', $copy['youtube']['metadata']['title']);
        $this->assertSame('Kajian rutin pekanan.', $copy['youtube']['metadata']['description']);
        $this->assertSame(['kajian', 'aqidah'], $copy['youtube']['metadata']['tags']);
        $this->assertSame('27', $copy['youtube']['metadata']['category_id']);
        $this->assertSame('id', $copy['youtube']['metadata']['default_language']);
        $this->assertSame('unlisted', $copy['youtube']['metadata']['privacy_status']);
        $this->assertSame('PL123', $copy['youtube']['metadata']['playlist_id']);
    }

    #[Test]
    public function it_never_carries_over_anything_about_the_recording_or_the_video(): void
    {
        /*
         * The test that matters. A duplicate inheriting youtube_video_id would
         * let the studio believe a project with no render at all was already
         * published — and every correction path keys off that id, so
         * "replace this video" would target the original's live lecture.
         */
        $original = ContentProject::factory()->for($this->user)->create([
            'working_title' => 'Already published',
            'source_audio_path' => 'content/abc/source/audio.mp3',
            'source_audio_original_name' => 'lecture.mp3',
            'source_audio_duration' => 3600.0,
            'background_image_path' => 'content/abc/source/bg.jpg',
            'output_path' => 'content/abc/output/video.mp4',
            'render_status' => RenderStatus::Rendered,
            'rendered_at' => Carbon::parse('2026-06-01T09:00:00Z'),
            'drive_status' => DriveStatus::Uploaded,
            'drive_file_id' => 'DRIVE123',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_video_id' => 'LIVE123',
            'youtube_uploaded_at' => Carbon::parse('2026-06-01T10:00:00Z'),
        ]);

        $copy = $this->duplicate($original);
        $model = ContentProject::where('uuid', $copy['id'])->firstOrFail();

        $this->assertNull($model->youtube_video_id);
        $this->assertNull($model->drive_file_id);
        $this->assertNull($model->output_path);
        $this->assertNull($model->source_audio_path);
        $this->assertNull($model->background_image_path);
        $this->assertNull($model->rendered_at);
        $this->assertNull($model->youtube_uploaded_at);

        $this->assertSame(RenderStatus::Draft, $model->render_status);
        $this->assertSame(DriveStatus::Pending, $model->drive_status);
        $this->assertSame(YouTubeStatus::Pending, $model->youtube_status);

        // And the original is untouched by any of it.
        $this->assertSame('LIVE123', $original->fresh()->youtube_video_id);
    }

    #[Test]
    public function the_schedule_is_dropped_rather_than_inherited(): void
    {
        // A publish time names one moment, and the moment the original was
        // given either belongs to the video already on the channel or has
        // already passed — in which case the upload would refuse it outright.
        $original = ContentProject::factory()->for($this->user)->create([
            'youtube_metadata' => [
                'title' => 'Scheduled one',
                'publish_at' => '2027-01-01T09:00:00Z',
            ],
        ]);

        $copy = $this->duplicate($original);

        $this->assertSame('Scheduled one', $copy['youtube']['metadata']['title']);
        $this->assertArrayNotHasKey('publish_at', $copy['youtube']['metadata']);
        $this->assertNull($copy['youtube']['planned_publish_at']);
    }

    #[Test]
    public function the_copy_takes_the_next_free_sequence_rather_than_the_original_s(): void
    {
        // TEMA #11 is drawn on the frame. Two of them is a rendering bug that
        // only shows up in the finished video.
        $topic = ContentTopic::factory()->for($this->user)->create();

        $original = ContentProject::factory()->for($this->user)->create([
            'topic_id' => $topic->id,
            'topic_sequence' => 11,
        ]);

        $this->assertSame(12, $this->duplicate($original)['topic_sequence']);
    }

    #[Test]
    public function a_project_with_no_topic_gets_no_sequence(): void
    {
        $original = ContentProject::factory()->for($this->user)->create([
            'topic_id' => null,
            'topic_sequence' => 4,
        ]);

        $this->assertNull($this->duplicate($original)['topic_sequence']);
    }

    #[Test]
    public function the_title_is_marked_as_a_copy(): void
    {
        $original = ContentProject::factory()->for($this->user)->create([
            'working_title' => 'Kajian Tematik #11',
        ]);

        $this->assertSame('Kajian Tematik #11 (copy)', $this->duplicate($original)['working_title']);
    }

    #[Test]
    public function a_long_title_keeps_the_suffix_and_loses_its_tail(): void
    {
        // Trimmed from the title, never from the suffix: a truncated
        // "Kajian… (cop" is two rows that look identical in the Studio list,
        // which is the thing the suffix exists to prevent.
        $original = ContentProject::factory()->for($this->user)->create([
            'working_title' => str_repeat('a', 120),
        ]);

        $title = $this->duplicate($original)['working_title'];

        $this->assertSame(100, mb_strlen($title));
        $this->assertStringEndsWith(' (copy)', $title);
    }

    #[Test]
    public function duplicating_twice_produces_two_distinct_slugs(): void
    {
        $original = ContentProject::factory()->for($this->user)->create([
            'working_title' => 'Same name',
        ]);

        $first = $this->duplicate($original);
        $second = $this->duplicate($original);

        $this->assertNotSame($first['slug'], $second['slug']);
        $this->assertNotSame($first['id'], $second['id']);
    }

    #[Test]
    public function another_users_project_cannot_be_duplicated(): void
    {
        // 404 rather than 403: whether somebody else's project exists is not
        // a fact this endpoint should confirm.
        $stranger = ContentProject::factory()->for(User::factory()->create())->create();

        $this->postJson("/api/v1/content-projects/{$stranger->uuid}/duplicate")
            ->assertNotFound();

        $this->assertSame(1, ContentProject::count());
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $project = ContentProject::factory()->for($this->user)->create();

        app('auth')->forgetGuards();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/duplicate")
            ->assertUnauthorized();
    }
}
