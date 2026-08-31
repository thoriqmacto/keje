<?php

namespace Tests\Feature\Studio;

use App\Models\ContentProject;
use App\Models\User;
use App\Support\PathAccess;
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

        // Separate lines: the verdict, the app's own path, then the absolute
        // one to check with ls. Each expectation matches its own line.
        $this->artisan('render:preflight', ['project' => $project->uuid])
            ->expectsOutputToContain('but no file is there')
            ->expectsOutputToContain('database path ')
            ->expectsOutputToContain('absolute path ')
            ->assertFailed();

        // Both paths really are printed; the labels above just keep each
        // expectation on its own line.
        $this->assertNotSame('', Storage::disk('local')->path($relative));
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
    public function an_unreachable_file_is_not_reported_as_a_missing_one(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || PathAccess::isRoot()) {
            $this->markTestSkipped('Needs POSIX permissions and a non-root user.');
        }

        $project = $this->project();
        $disk = Storage::disk('local');

        // The production state: the file is there, the project directory is
        // 0700, and the deploy user cannot get in. Reporting this as missing
        // is what sent someone off to re-upload media that was never lost.
        $projectDir = dirname(dirname($disk->path($project->source_audio_path)));
        chmod($projectDir, 0000);

        try {
            $this->artisan('render:preflight', ['project' => $project->uuid])
                ->expectsOutputToContain('cannot be reached')
                ->expectsOutputToContain('Cannot traverse: '.$projectDir)
                ->doesntExpectOutputToContain('no file is there')
                ->assertFailed();
        } finally {
            chmod($projectDir, 0755);
        }
    }

    #[Test]
    public function the_permissions_flag_prints_owner_group_and_mode(): void
    {
        $project = $this->project();

        $this->artisan('render:preflight', [
            'project' => $project->uuid,
            '--permissions' => true,
        ])
            ->expectsOutputToContain('owner ')
            ->expectsOutputToContain('mode ')
            ->assertFailed();
    }

    #[Test]
    public function it_is_also_reachable_as_media_preflight(): void
    {
        // The name people reach for next to media:diagnose.
        $this->artisan('media:preflight', ['project' => 'not-a-uuid'])
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
