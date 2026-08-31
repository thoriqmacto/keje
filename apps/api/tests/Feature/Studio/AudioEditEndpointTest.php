<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\User;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saving cuts, and hearing the recording in order to choose them.
 */
class AudioEditEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function project(User $user): ContentProject
    {
        return ContentProject::factory()->withMediaFiles()->create([
            'user_id' => $user->id,
            'source_audio_duration' => 60.0,
            'primary_title' => 'Keutamaan Lapar',
        ]);
    }

    #[Test]
    public function saving_a_cut_records_it_with_the_arithmetic_done(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", [
            'audio_edits' => [['type' => 'cut', 'start' => 18, 'end' => 23]],
        ])
            ->assertOk()
            ->assertJsonPath('data.audio_edits.cuts.0.start', 18)
            ->assertJsonPath('data.audio_edits.cuts.0.end', 23)
            ->assertJsonPath('data.audio_edits.removed_duration', 5)
            // What the render will be, and what progress measures against.
            ->assertJsonPath('data.audio_edits.effective_duration', 55);
    }

    #[Test]
    public function the_uploaded_recording_is_never_touched(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);
        $before = Storage::disk('local')->get($project->source_audio_path);

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", [
            'audio_edits' => [['type' => 'cut', 'start' => 18, 'end' => 23]],
        ])->assertOk();

        // Non-destructive: a mis-typed timestamp costs a re-render, never the
        // lecture.
        $this->assertSame($before, Storage::disk('local')->get($project->source_audio_path));
    }

    #[Test]
    public function cuts_can_be_cleared_again(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);
        $project->forceFill(['audio_edits' => [['type' => 'cut', 'start' => 18.0, 'end' => 23.0]]])->save();

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", ['audio_edits' => []])
            ->assertOk()
            ->assertJsonPath('data.audio_edits.effective_duration', 60);

        $this->assertNull($project->refresh()->audio_edits);
    }

    #[Test]
    public function an_invalid_range_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", [
            'audio_edits' => [['type' => 'cut', 'start' => 50, 'end' => 90]],
        ])->assertStatus(422);

        $this->assertNull($project->refresh()->audio_edits);
    }

    #[Test]
    public function editing_cuts_makes_an_existing_render_stale(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);

        $project->forceFill([
            'output_path' => $project->storageDirectory().'/renders/output.mp4',
            'last_render_input_hash' => app(RenderInputFingerprint::class)
                ->for($project->load(['topic', 'speaker'])),
        ])->save();

        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertJsonPath('data.render.stale', false);

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", [
            'audio_edits' => [['type' => 'cut', 'start' => 18, 'end' => 23]],
        ])->assertOk();

        // The MP4 still contains the removed five seconds, so it no longer
        // represents the project.
        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertJsonPath('data.render.stale', true);
    }

    #[Test]
    public function editing_needs_a_recording_to_edit(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", [
            'audio_edits' => [['type' => 'cut', 'start' => 1, 'end' => 2]],
        ])->assertStatus(422);
    }

    #[Test]
    public function another_users_project_cannot_be_edited(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $project = $this->project(User::factory()->create());

        $this->putJson("/api/v1/content-projects/{$project->uuid}/audio-edits", [
            'audio_edits' => [],
        ])->assertNotFound();
    }

    // ── Playback ────────────────────────────────────────────────────────────

    #[Test]
    public function media_links_include_the_source_audio_before_any_render(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);

        // The editor needs playback long before anything has been encoded.
        $this->getJson("/api/v1/content-projects/{$project->uuid}/media-links")
            ->assertOk()
            ->assertJsonPath('data.video_url', null)
            ->assertJsonMissing(['audio_url' => null]);
    }

    #[Test]
    public function the_signed_link_streams_the_recording(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);

        $url = $this->getJson("/api/v1/content-projects/{$project->uuid}/media-links")
            ->json('data.audio_url');

        // Signed rather than authenticated: an <audio> element cannot attach
        // a bearer token, so the signature is the capability.
        $this->get($url)
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    #[Test]
    public function an_unsigned_or_tampered_link_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->project($user);

        $this->get("/api/v1/content-projects/{$project->uuid}/source-audio")
            ->assertForbidden();

        $url = URL::temporarySignedRoute('content-projects.source-audio', now()->addMinute(), [
            'project' => $project->uuid,
        ]);

        $this->get($url.'&x=1')->assertForbidden();
    }
}
