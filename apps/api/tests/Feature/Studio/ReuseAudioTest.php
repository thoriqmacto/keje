<?php

namespace Tests\Feature\Studio;

use App\Enums\RenderStatus;
use App\Exceptions\Media\UnusableMediaException;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\User;
use App\Services\Media\FfprobeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reusing a recording that is already on the server.
 *
 * One lecture becomes several videos — the same three hours trimmed three
 * different ways — and re-uploading half a gigabyte each time is a slow way
 * to say "that one again".
 *
 * Two things these tests care about most. The recording is **copied**, not
 * shared: every other part of this system assumes a project owns the files
 * under its own directory, so a second project pointing at the first one's
 * file would turn a prune into somebody else's data loss. And the browser
 * names a **project**, never a path — this is the one feature that could
 * plausibly have been built as "tell me which file to use".
 *
 * ffprobe is mocked throughout; normal test runs must not need FFmpeg.
 */
class ReuseAudioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /**
     * A project holding its own recording, at its own path.
     *
     * Deliberately not the factory's withMediaFiles(): that uses one shared
     * fixture path for every project, which is exactly the ambiguity these
     * tests are about.
     */
    private function holder(string $title, string $contents = 'the-lecture-bytes'): ContentProject
    {
        $project = ContentProject::factory()->for($this->user)->create([
            'working_title' => $title,
            'source_audio_original_name' => 'kajian-full.mp3',
            'source_audio_mime' => 'audio/mpeg',
            'source_audio_size' => strlen($contents),
            'source_audio_duration' => 10_800.5,
            'source_audio_codec' => 'mp3',
            'source_audio_sample_rate' => 44100,
            'source_audio_channels' => 2,
        ]);

        $path = "content/{$project->uuid}/source/audio.mp3";
        Storage::disk('local')->put($path, $contents);
        $project->forceFill(['source_audio_path' => $path])->save();

        return $project->refresh();
    }

    private function fakeProbe(?UnusableMediaException $throws = null): void
    {
        $mock = Mockery::mock(FfprobeService::class);

        if ($throws !== null) {
            $mock->shouldReceive('inspectAudio')->andThrow($throws);
        } else {
            $mock->shouldReceive('inspectAudio')->andReturn([
                'codec' => 'mp3',
                'duration' => 9_999.5,
                'sample_rate' => 44100,
                'channels' => 2,
                'bitrate' => 128000,
            ]);
        }

        $this->instance(FfprobeService::class, $mock);
    }

    private function reuse(ContentProject $target, ContentProject $source)
    {
        return $this->postJson("/api/v1/content-projects/{$target->uuid}/audio/reuse", [
            'source_project_id' => $source->uuid,
        ]);
    }

    // ── The copy ────────────────────────────────────────────────────────────

    #[Test]
    public function the_recording_is_copied_into_the_reusing_project_s_own_directory(): void
    {
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $target = ContentProject::factory()->for($this->user)->create();

        $this->reuse($target, $source)->assertOk();

        $target->refresh();

        $this->assertSame("content/{$target->uuid}/source/audio.mp3", $target->source_audio_path);
        Storage::disk('local')->assertExists($target->source_audio_path);

        // The two paths are genuinely different files, which is the whole
        // point: a prune of either must not reach the other.
        $this->assertNotSame($source->source_audio_path, $target->source_audio_path);
        $this->assertSame(
            Storage::disk('local')->get($source->source_audio_path),
            Storage::disk('local')->get($target->source_audio_path),
        );
    }

    #[Test]
    public function the_source_project_is_left_completely_alone(): void
    {
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $before = $source->toArray();

        $this->reuse(ContentProject::factory()->for($this->user)->create(), $source)->assertOk();

        Storage::disk('local')->assertExists($source->source_audio_path);
        $this->assertSame($before['source_audio_path'], $source->fresh()->source_audio_path);
        $this->assertSame($before['render_status'], $source->fresh()->toArray()['render_status']);
    }

    #[Test]
    public function deleting_the_source_project_does_not_take_the_copy_with_it(): void
    {
        /*
         * The reason this is a copy and not a shared path, stated as a test.
         * purge() deletes a project's whole directory; if the reusing project
         * pointed into that directory, deleting the original would silently
         * empty a project somebody was still working on.
         */
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $target = ContentProject::factory()->for($this->user)->create();

        $this->reuse($target, $source)->assertOk();
        $this->deleteJson("/api/v1/content-projects/{$source->uuid}")->assertNoContent();

        $target->refresh();

        Storage::disk('local')->assertExists($target->source_audio_path);
        $this->assertSame('the-lecture-bytes', Storage::disk('local')->get($target->source_audio_path));
    }

    #[Test]
    public function the_facts_are_re_probed_and_the_file_name_carried_over(): void
    {
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $target = ContentProject::factory()->for($this->user)->create();

        $this->reuse($target, $source)
            ->assertOk()
            ->assertJsonPath('data.source_audio.original_name', 'kajian-full.mp3')
            // 9999.5 is what ffprobe reported for the copy; the row it came
            // from says 10800.5. Asserting the former is what makes this a
            // test of re-probing rather than of copying a number across.
            ->assertJsonPath('data.source_audio.duration', 9_999.5)
            ->assertJsonPath('data.source_audio.codec', 'mp3')
            // Measured from the copy that actually landed, not copied from
            // the row it came from.
            ->assertJsonPath('data.source_audio.size', strlen('the-lecture-bytes'));
    }

    #[Test]
    public function a_project_with_both_files_becomes_ready_to_render(): void
    {
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $target = ContentProject::factory()->for($this->user)->create([
            'background_image_path' => 'content/other/source/background.jpg',
            'background_image_original_name' => 'bg.jpg',
        ]);

        $this->reuse($target, $source)->assertOk();

        $this->assertSame(RenderStatus::MediaReady, $target->fresh()->render_status);
    }

    // ── Trims ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_reusing_project_starts_with_nothing_trimmed(): void
    {
        // The whole point of reusing: each project wants a different section,
        // so the copy arrives whole rather than inheriting the original's cuts.
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $source->forceFill(['audio_edits' => [['type' => 'cut', 'start' => 0.0, 'end' => 600.0]]])->save();

        $target = ContentProject::factory()->for($this->user)->create();

        $this->reuse($target, $source)->assertOk();

        $this->assertNull($target->fresh()->audio_edits);
    }

    #[Test]
    public function cuts_drawn_against_an_earlier_recording_are_cleared(): void
    {
        /*
         * Cuts are absolute timestamps with nothing tying them to the file
         * they were drawn against. Left in place across a replacement they
         * apply to the new timeline instead — removing ninety seconds from
         * somewhere nobody chose, in a video that renders without complaint
         * and is simply wrong.
         */
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $target = $this->holder('Had its own recording');
        $target->forceFill(['audio_edits' => [['type' => 'cut', 'start' => 30.0, 'end' => 90.0]]])->save();

        $this->reuse($target, $source)->assertOk();

        $this->assertNull($target->fresh()->audio_edits);
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    #[Test]
    public function a_project_cannot_reuse_its_own_recording(): void
    {
        /*
         * The one way this endpoint could destroy a recording: the copy clears
         * the destination first, so copying a project onto itself would delete
         * the file and then read from the hole it left.
         */
        $this->fakeProbe();

        $project = $this->holder('Full lecture');

        $this->reuse($project, $project)
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_project_id');

        Storage::disk('local')->assertExists($project->source_audio_path);
        $this->assertSame('the-lecture-bytes', Storage::disk('local')->get($project->fresh()->source_audio_path));
    }

    #[Test]
    public function a_recording_that_has_been_pruned_is_refused(): void
    {
        // Pruning nulls the path and keeps the descriptive columns, so a
        // project can describe a recording whose bytes are long gone.
        $this->fakeProbe();

        $source = $this->holder('Freed already');
        $source->forceFill(['source_audio_path' => null])->save();

        $this->reuse(ContentProject::factory()->for($this->user)->create(), $source)
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_project_id');
    }

    #[Test]
    public function a_row_pointing_at_a_file_that_is_gone_is_refused(): void
    {
        // The column and the disk disagreeing is exactly what a restored
        // database or a half-finished deploy leaves behind.
        $this->fakeProbe();

        $source = $this->holder('Missing on disk');
        Storage::disk('local')->delete($source->source_audio_path);

        $this->reuse(ContentProject::factory()->for($this->user)->create(), $source)
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_project_id');
    }

    #[Test]
    public function a_copy_that_turns_out_unusable_leaves_no_file_behind(): void
    {
        $this->fakeProbe(throws: new UnusableMediaException('That file contains no audio track.'));

        $source = $this->holder('Full lecture');
        $target = ContentProject::factory()->for($this->user)->create();

        $this->reuse($target, $source)->assertStatus(422);

        Storage::disk('local')->assertMissing("content/{$target->uuid}/source/audio.mp3");
        $this->assertNull($target->fresh()->source_audio_path);
    }

    #[Test]
    public function another_users_recording_cannot_be_reused(): void
    {
        // 404-equivalent by validation: whether somebody else's project exists
        // is not a fact this endpoint should confirm.
        $this->fakeProbe();

        $stranger = ContentProject::factory()->for(User::factory()->create())->create([
            'source_audio_path' => 'content/stranger/source/audio.mp3',
        ]);
        $target = ContentProject::factory()->for($this->user)->create();

        $this->reuse($target, $stranger)
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_project_id');

        $this->assertNull($target->fresh()->source_audio_path);
    }

    #[Test]
    public function another_users_project_cannot_be_the_target(): void
    {
        $this->fakeProbe();

        $source = $this->holder('Full lecture');
        $stranger = ContentProject::factory()->for(User::factory()->create())->create();

        $this->reuse($stranger, $source)->assertNotFound();
    }

    // ── The library ─────────────────────────────────────────────────────────

    #[Test]
    public function the_library_lists_only_recordings_still_on_the_server(): void
    {
        $this->holder('Still here');

        $pruned = $this->holder('Freed already');
        $pruned->forceFill(['source_audio_path' => null])->save();

        ContentProject::factory()->for($this->user)->create(['working_title' => 'Never had one']);

        $titles = $this->getJson('/api/v1/content-projects/audio-sources')
            ->assertOk()
            ->json('data.*.working_title');

        $this->assertSame(['Still here'], $titles);
    }

    #[Test]
    public function the_library_is_scoped_to_the_caller(): void
    {
        $this->holder('Mine');

        ContentProject::factory()->for(User::factory()->create())->create([
            'working_title' => 'Somebody else\'s',
            'source_audio_path' => 'content/stranger/source/audio.mp3',
        ]);

        $titles = $this->getJson('/api/v1/content-projects/audio-sources')
            ->assertOk()
            ->json('data.*.working_title');

        $this->assertSame(['Mine'], $titles);
    }

    #[Test]
    public function the_library_describes_the_recording_without_naming_a_file_on_disk(): void
    {
        /*
         * The rule this whole feature had to be designed around. A picker
         * needs to describe the choice well enough to recognise it, and the
         * one thing it must not carry is a path — otherwise the obvious next
         * step is an endpoint that accepts one back.
         */
        $topic = ContentTopic::factory()->for($this->user)->create(['name' => 'Aqidah']);
        $project = $this->holder('Full lecture');
        $project->forceFill(['topic_id' => $topic->id])->save();

        $response = $this->getJson('/api/v1/content-projects/audio-sources')->assertOk();

        $response
            ->assertJsonPath('data.0.working_title', 'Full lecture')
            ->assertJsonPath('data.0.topic', 'Aqidah')
            ->assertJsonPath('data.0.original_name', 'kajian-full.mp3')
            ->assertJsonPath('data.0.duration', 10_800.5);

        $body = $response->getContent();

        $this->assertStringNotContainsString('content/', $body);
        $this->assertStringNotContainsString('source_audio_path', $body);
        $this->assertStringNotContainsString(storage_path(), $body);
    }

    #[Test]
    public function the_library_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/content-projects/audio-sources')->assertUnauthorized();
    }
}
