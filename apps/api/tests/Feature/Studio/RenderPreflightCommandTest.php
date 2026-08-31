<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command answers "why won't this render", so the assertions are about
 * whether it names the blocker — and, for a missing file, the path.
 */
class RenderPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function project(array $attributes = []): ContentProject
    {
        return ContentProject::factory()->withMediaFiles()->create([
            'user_id' => User::factory()->create()->id,
            'primary_title' => 'Keutamaan Lapar',
            ...$attributes,
        ]);
    }

    #[Test]
    public function an_unknown_project_fails_rather_than_reporting_nothing(): void
    {
        $this->artisan('render:preflight', ['project' => 'not-a-uuid'])
            ->assertFailed();
    }

    #[Test]
    public function a_recorded_but_absent_source_names_the_path_it_looked_for(): void
    {
        $project = $this->project();
        $relative = $project->source_audio_path;

        Storage::disk('local')->delete($relative);

        // Two separate lines: the verdict, then the absolute path to check
        // with ls. Each expectation matches its own line.
        $this->artisan('render:preflight', ['project' => $project->uuid])
            ->expectsOutputToContain('but no file is there')
            ->expectsOutputToContain('Looked in: '.Storage::disk('local')->path($relative))
            ->assertFailed();
    }

    #[Test]
    public function a_source_that_was_never_uploaded_says_so_instead(): void
    {
        // A different failure with a different fix: nothing to chase on disk.
        $project = $this->project(['source_audio_path' => null]);

        $this->artisan('render:preflight', ['project' => $project->uuid])
            ->expectsOutputToContain('not uploaded')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_primary_title_is_reported(): void
    {
        $project = $this->project(['primary_title' => null]);

        $this->artisan('render:preflight', ['project' => $project->uuid])
            ->expectsOutputToContain('Primary title')
            ->assertFailed();
    }

    #[Test]
    public function text_that_does_not_fit_is_caught_here_too(): void
    {
        $project = $this->project([
            'primary_title' => str_repeat('Keutamaan Lapar Hidup Sederhana ', 8),
        ]);

        $this->artisan('render:preflight', ['project' => $project->uuid])
            ->expectsOutputToContain('Template layout')
            ->assertFailed();
    }
}
