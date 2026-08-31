<?php

namespace Tests\Feature\Studio;

use App\Enums\RenderJobStatus;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command exists to answer "why is progress still 0", so what it asserts
 * is the verdict, not the layout.
 */
class RenderStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private function project(): ContentProject
    {
        return ContentProject::factory()->withMedia()->create([
            'user_id' => User::factory()->create()->id,
            'primary_title' => 'Keutamaan Lapar',
        ]);
    }

    #[Test]
    public function a_long_queued_attempt_names_the_worker_command(): void
    {
        $project = $this->project();
        $attempt = $project->renderJobs()->create([
            'status' => RenderJobStatus::Queued,
            'progress_percent' => 0,
        ]);
        $attempt->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('render:status')
            ->expectsOutputToContain('Keutamaan Lapar')
            ->expectsOutputToContain('queue:work')
            ->assertSuccessful();
    }

    #[Test]
    public function a_fresh_queued_attempt_is_not_reported_as_a_problem(): void
    {
        $this->project()->renderJobs()->create([
            'status' => RenderJobStatus::Queued,
            'progress_percent' => 0,
        ]);

        $this->artisan('render:status')
            ->doesntExpectOutputToContain('queue:work')
            ->assertSuccessful();
    }

    #[Test]
    public function a_worker_that_died_mid_encode_is_distinguished_from_a_slow_one(): void
    {
        $project = $this->project();

        // Claimed, then nothing — no progress was ever written, and nothing
        // is left alive to mark it failed.
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'progress_percent' => 0,
            'started_at' => now()->subMinutes(45),
        ]);

        $this->artisan('render:status')
            ->expectsOutputToContain('worker may have')
            ->assertSuccessful();
    }

    #[Test]
    public function a_render_actually_encoding_is_not_called_stuck(): void
    {
        $project = $this->project();
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'progress_percent' => 37,
            'started_at' => now()->subMinutes(45),
        ]);

        $this->artisan('render:status')
            ->expectsOutputToContain('Encoding')
            ->doesntExpectOutputToContain('worker may have')
            ->assertSuccessful();
    }

    #[Test]
    public function a_failed_attempt_shows_its_error(): void
    {
        $project = $this->project();
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Failed,
            'progress_percent' => 0,
            'error_message' => 'The subtitle does not fit on two lines.',
            'finished_at' => now(),
        ]);

        $this->artisan('render:status')
            ->expectsOutputToContain('does not fit')
            ->assertSuccessful();
    }

    #[Test]
    public function it_can_be_narrowed_to_one_project(): void
    {
        $mine = $this->project();
        $mine->renderJobs()->create(['status' => RenderJobStatus::Queued, 'progress_percent' => 0]);

        $other = $this->project();
        $other->forceFill(['primary_title' => 'Another Lecture'])->save();
        $other->renderJobs()->create(['status' => RenderJobStatus::Queued, 'progress_percent' => 0]);

        $this->artisan('render:status', ['--project' => $mine->uuid])
            ->expectsOutputToContain('Keutamaan Lapar')
            ->doesntExpectOutputToContain('Another Lecture')
            ->assertSuccessful();
    }
}
