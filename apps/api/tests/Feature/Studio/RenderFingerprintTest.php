<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What must and must not invalidate a finished render.
 *
 * The cost of getting this wrong runs both ways: too eager and every rename
 * throws away a two-hour encode, too lax and the studio presents an MP4 that
 * no longer matches the project it claims to be.
 */
class RenderFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private RenderInputFingerprint $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprint = app(RenderInputFingerprint::class);
    }

    private function project(array $attributes = []): ContentProject
    {
        $user = User::factory()->create();

        return ContentProject::factory()->withMedia()->create([
            'user_id' => $user->id,
            'primary_title' => 'Keutamaan Lapar',
            ...$attributes,
        ])->load(['topic', 'speaker']);
    }

    #[Test]
    public function the_same_inputs_hash_the_same_way(): void
    {
        $project = $this->project();

        $this->assertSame(
            $this->fingerprint->for($project),
            $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])),
        );
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function renderInputs(): array
    {
        return [
            'primary title' => ['primary_title', 'Something Else'],
            'subtitle' => ['subtitle', 'A new subtitle'],
            'part number' => ['part_number', 4],
            'topic sequence' => ['topic_sequence', 12],
            'template' => ['template_key', 'kajian-tematik'],
        ];
    }

    #[Test]
    public function every_drawn_field_changes_the_fingerprint(): void
    {
        foreach (self::renderInputs() as $label => [$column, $value]) {
            $project = $this->project();
            $before = $this->fingerprint->for($project);

            $project->forceFill([$column => $value])->save();

            if ($column === 'template_key') {
                // Only template there is; equal value must hash equal.
                $this->assertSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])), $label);

                continue;
            }

            $this->assertNotSame(
                $before,
                $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])),
                "changing the {$label} should change the fingerprint",
            );
        }
    }

    #[Test]
    public function changing_the_speaker_changes_the_fingerprint(): void
    {
        $project = $this->project();
        $before = $this->fingerprint->for($project);

        $speaker = Speaker::factory()->create(['user_id' => $project->user_id, 'name' => 'Someone Else']);
        $project->forceFill(['speaker_id' => $speaker->id])->save();

        // The speaker's render name is drawn on the frame.
        $this->assertNotSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function changing_the_topic_changes_the_fingerprint(): void
    {
        $project = $this->project();
        $before = $this->fingerprint->for($project);

        $topic = ContentTopic::factory()->create(['user_id' => $project->user_id, 'name' => 'Riyadhush Shalihin']);
        $project->forceFill(['topic_id' => $topic->id])->save();

        $this->assertNotSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function audio_edits_change_the_fingerprint(): void
    {
        $project = $this->project();
        $before = $this->fingerprint->for($project);

        $project->forceFill(['audio_edits' => [['type' => 'cut', 'start' => 18.0, 'end' => 23.0]]])->save();

        $this->assertNotSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function replacing_the_audio_changes_the_fingerprint(): void
    {
        $project = $this->project();
        $before = $this->fingerprint->for($project);

        // Same server-controlled filename, different bytes — which is exactly
        // why the path alone cannot be the identity.
        $project->forceFill(['source_audio_size' => 9_999_999, 'source_audio_duration' => 1200.0])->save();

        $this->assertNotSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function the_working_title_does_not_invalidate_a_render(): void
    {
        // A label for humans, never drawn. Renaming must not throw away an
        // encode that can take hours.
        $project = $this->project();
        $before = $this->fingerprint->for($project);

        $project->forceFill(['working_title' => 'A completely different name'])->save();

        $this->assertSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function publishing_metadata_does_not_invalidate_a_render(): void
    {
        // YouTube's business, not FFmpeg's: none of it reaches a frame.
        $project = $this->project();
        $before = $this->fingerprint->for($project);

        $project->forceFill([
            'youtube_metadata' => ['title' => 'New title', 'privacy_status' => 'public'],
        ])->save();

        $this->assertSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function render_settings_key_order_does_not_matter(): void
    {
        $project = $this->project(['render_settings' => ['loudnorm' => true, 'other' => 1]]);
        $before = $this->fingerprint->for($project);

        $project->forceFill(['render_settings' => ['other' => 1, 'loudnorm' => true]])->save();

        // Re-saving the same settings in a different order is not a change.
        $this->assertSame($before, $this->fingerprint->for($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function staleness_needs_both_an_output_and_a_recorded_hash(): void
    {
        $project = $this->project();

        // Never rendered: unknown, not stale.
        $this->assertFalse($this->fingerprint->isStale($project));

        // Rendered before fingerprints existed: also not stale, or every
        // historical project would light up on the day this ships.
        $project->forceFill(['output_path' => 'content/x/renders/output.mp4'])->save();
        $this->assertFalse($this->fingerprint->isStale($project->fresh()->load(['topic', 'speaker'])));
    }

    #[Test]
    public function an_edit_after_a_render_makes_the_output_stale(): void
    {
        $project = $this->project();

        $project->forceFill([
            'output_path' => 'content/x/renders/output.mp4',
            'last_render_input_hash' => $this->fingerprint->for($project),
        ])->save();

        $this->assertFalse($this->fingerprint->isStale($project->fresh()->load(['topic', 'speaker'])));

        $project->forceFill(['subtitle' => 'Edited after the render'])->save();

        $this->assertTrue($this->fingerprint->isStale($project->fresh()->load(['topic', 'speaker'])));
    }
}
