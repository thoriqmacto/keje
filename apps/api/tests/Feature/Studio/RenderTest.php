<?php

namespace Tests\Feature\Studio;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStatus;
use App\Exceptions\Media\RenderFailedException;
use App\Jobs\RenderContentProjectJob;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Media\FfmpegService;
use App\Services\Media\FontMetrics;
use App\Services\Media\TemplateRegistry;
use App\Services\Media\TextLayoutService;
use App\Services\Media\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Render dispatch, state transitions and the FFmpeg command itself.
 *
 * FFmpeg is never executed here: the argument builder is asserted directly,
 * and the job is driven with a faked FfmpegService.
 */
class RenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Dispatching a render now requires the source files to really exist,
        // so every project built here gets them on a faked disk.
        Storage::fake('local');
    }

    private function renderableProject(User $user): ContentProject
    {
        $project = ContentProject::factory()->withMediaFiles()->create([
            'user_id' => $user->id,
            'topic_id' => ContentTopic::factory()->create([
                'user_id' => $user->id, 'name' => 'Riyadhush Shalihin',
            ])->id,
            'topic_sequence' => 11,
            'speaker_id' => Speaker::factory()->create([
                'user_id' => $user->id, 'name' => 'Syafiq Riza Basalamah',
            ])->id,
        ]);

        return $project->load(['topic', 'speaker']);
    }

    // ── Dispatch ────────────────────────────────────────────────────────────

    #[Test]
    public function rendering_queues_a_job_and_returns_immediately(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")
            ->assertStatus(202)
            ->assertJsonPath('data.render.status', 'queued');

        Queue::assertPushed(RenderContentProjectJob::class);

        $this->assertSame(RenderStatus::Queued, $project->refresh()->render_status);
        $this->assertSame(1, $project->renderJobs()->count());
    }

    #[Test]
    public function the_render_job_runs_on_the_dedicated_media_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);

        Queue::assertPushed(
            RenderContentProjectJob::class,
            fn (RenderContentProjectJob $job): bool => $job->queue === 'media',
        );
    }

    #[Test]
    public function rendering_without_media_is_refused(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function rendering_without_a_primary_title_is_refused(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->withMedia()->create([
            'user_id' => $user->id,
            'primary_title' => null,
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_title']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function unfittable_text_is_refused_before_anything_is_queued(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = ContentProject::factory()->withMediaFiles()->create([
            'user_id' => $user->id,
            'primary_title' => str_repeat('Keutamaan Lapar Hidup Sederhana ', 8),
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_title']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_duplicate_render_click_does_not_queue_a_second_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);
        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(409);

        Queue::assertPushed(RenderContentProjectJob::class, 1);
        $this->assertSame(1, $project->renderJobs()->count());
    }

    #[Test]
    public function a_retry_after_failure_creates_a_new_attempt(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Failed])->save();
        $project->renderJobs()->create(['status' => RenderJobStatus::Failed]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);

        // History is preserved: the old attempt is still there.
        $this->assertSame(2, $project->renderJobs()->count());
    }

    #[Test]
    public function rendering_another_users_project_is_refused(): void
    {
        Queue::fake();
        $theirs = ContentProject::factory()->withMedia()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/content-projects/{$theirs->uuid}/render")->assertStatus(404);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_render_is_refused_when_the_recorded_source_files_are_gone(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());

        // Columns set, files absent: what a deploy that replaced storage/ or
        // a worker on a different release leaves behind.
        $project = ContentProject::factory()->withMedia()->create([
            'user_id' => $user->id,
            'primary_title' => 'Keutamaan Lapar',
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio']);

        // The point of checking here: nothing is queued to fail later.
        Queue::assertNothingPushed();
        $this->assertSame(RenderStatus::MediaReady, $project->refresh()->render_status);
    }

    #[Test]
    public function a_missing_source_at_render_time_names_the_path_it_looked_for(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);

        // Deleted between queueing and the worker picking it up.
        Storage::disk('local')->delete($project->source_audio_path);

        $renderJob = $project->renderJobs()->create([
            'status' => RenderJobStatus::Queued,
            'progress_percent' => 0,
        ]);

        (new RenderContentProjectJob($project->id, $renderJob->id))
            ->handle(app(VideoRenderer::class));

        // "missing from storage" alone is a dead end; the path is what turns
        // it into something checkable with ls.
        $error = $project->refresh()->render_error;

        $this->assertStringContainsString($project->source_audio_path, $error);
        $this->assertStringContainsString('Re-upload', $error);
    }

    // ── Status ──────────────────────────────────────────────────────────────

    #[Test]
    public function render_status_reports_progress(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'progress_percent' => 43,
        ]);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'rendering')
            ->assertJsonPath('data.progress', 43);
    }

    #[Test]
    public function a_render_nobody_picks_up_is_reported_rather_than_left_at_zero(): void
    {
        // The suite runs on the sync driver, which would execute the render
        // in the request. Production uses the database driver, and this test
        // is about what that driver does when nothing drains it.
        config(['queue.default' => 'database']);

        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        // Queue the render for real — the row lands in the jobs table and
        // stays there, exactly as it does when no worker is listening to the
        // "media" queue.
        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);

        $this->assertDatabaseHas('jobs', ['queue' => 'media']);

        // Still queued a moment later: normal, and reported as such.
        $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.stalled', false);

        $this->travel(20)->minutes();

        $response = $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.stalled', true);

        // The message has to name the actual cause; "still queued" is what
        // the progress bar already said.
        $reason = $response->json('data.stalled_reason');

        // It has to name the worker service, not just say "still queued", and
        // not just hand over a command that dies with the shell.
        $this->assertStringContainsString('keje-worker.service', $reason);
        $this->assertStringContainsString('media', $reason);
        $this->assertStringContainsString('continuously', $reason);

        // The attempt is still queued, not failed: it will run the moment a
        // worker appears, and marking it failed would throw the work away.
        $this->assertSame(RenderJobStatus::Queued, $project->latestRenderJob()->status);
    }

    #[Test]
    public function a_running_render_is_never_reported_as_stalled(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();

        // A long render is not a stalled one — a lecture takes real minutes.
        $job = $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'progress_percent' => 12,
        ]);
        $job->forceFill(['created_at' => now()->subHour()])->save();

        $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.stalled', false)
            ->assertJsonPath('data.stalled_reason', null);
    }

    // ── Job state machine ───────────────────────────────────────────────────

    #[Test]
    public function a_successful_render_transitions_the_project_and_the_attempt(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $job = $project->renderJobs()->create(['status' => RenderJobStatus::Queued]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturn([
            'output_path' => 'content/x/renders/output.mp4',
            'size' => 12345,
            'duration' => 1800.0,
            'exit_code' => 0,
            'log' => 'ok',
        ]);

        (new RenderContentProjectJob($project->id, $job->id))->handle($renderer);

        $this->assertSame(RenderStatus::Rendered, $project->refresh()->render_status);
        $this->assertSame(RenderJobStatus::Succeeded, $job->refresh()->status);
        $this->assertSame(100, $job->progress_percent);
        $this->assertSame(12345, $project->output_size);
        $this->assertNotNull($project->rendered_at);
    }

    #[Test]
    public function a_failed_render_records_a_useful_message_without_leaking_internals(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $job = $project->renderJobs()->create(['status' => RenderJobStatus::Queued]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldReceive('render')->once()
            ->andThrow(new RenderFailedException('The source audio could not be decoded.'));

        (new RenderContentProjectJob($project->id, $job->id))->handle($renderer);

        $project->refresh();
        $this->assertSame(RenderStatus::Failed, $project->render_status);
        $this->assertSame('The source audio could not be decoded.', $project->render_error);
        $this->assertSame(RenderJobStatus::Failed, $job->refresh()->status);
        $this->assertStringNotContainsString(storage_path(), (string) $project->render_error);
    }

    #[Test]
    public function a_drive_failure_does_not_invalidate_a_good_render(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'drive_status' => \App\Enums\DriveStatus::Failed,
        ])->save();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/content-projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.render.status', 'rendered')
            ->assertJsonPath('data.drive.status', 'failed');
    }

    #[Test]
    public function a_job_whose_attempt_already_finished_does_not_re_run(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $job = $project->renderJobs()->create(['status' => RenderJobStatus::Succeeded]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldNotReceive('render');

        (new RenderContentProjectJob($project->id, $job->id))->handle($renderer);
    }

    // ── FFmpeg command ──────────────────────────────────────────────────────

    /** @return list<string> */
    private function buildArgs(array $content = []): array
    {
        $registry = app(TemplateRegistry::class);
        $layoutService = new TextLayoutService(app(FontMetrics::class));

        $layout = $layoutService->resolve($registry->get('kajian-tematik'), [
            'topic' => 'Riyadhush Shalihin',
            'topic_sequence' => 11,
            'speaker_name' => 'Syafiq Riza Basalamah',
            'primary_title' => 'Keutamaan Lapar, Hidup',
            'subtitle' => 'Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu',
            'part_number' => 3,
            ...$content,
        ]);

        $renderer = new VideoRenderer(
            $registry, $layoutService, app(FontMetrics::class), app(FfmpegService::class),
        );

        $textPaths = [];
        foreach ($layout['elements'] as $element) {
            if ($element['type'] === 'text') {
                $textPaths[$element['key']] = "/tmp/{$element['key']}.txt";
            }
        }

        return $renderer->buildArguments(
            layout: $layout,
            audioPath: '/tmp/audio.mp3',
            backgroundPath: '/tmp/bg.jpg',
            textPaths: $textPaths,
            outputPath: '/tmp/out.mp4',
            duration: 1800.0,
        );
    }

    private function filterGraph(array $args): string
    {
        return $args[array_search('-filter_complex', $args, true) + 1];
    }

    #[Test]
    public function the_command_encodes_to_the_youtube_ready_format(): void
    {
        $args = $this->buildArgs();

        foreach ([
            ['-c:v', 'libx264'],
            ['-profile:v', 'high'],
            ['-pix_fmt', 'yuv420p'],
            ['-r', '30'],
            ['-c:a', 'aac'],
            ['-ar', '48000'],
            ['-b:a', '256k'],
            ['-movflags', '+faststart'],
        ] as [$flag, $value]) {
            $index = array_search($flag, $args, true);
            $this->assertNotFalse($index, "Missing {$flag}");
            $this->assertSame($value, $args[$index + 1], "Wrong value for {$flag}");
        }
    }

    #[Test]
    public function the_output_duration_is_bounded_by_the_audio_length(): void
    {
        $args = $this->buildArgs();

        // -loop 1 stills make the video branch infinite, so -shortest alone
        // would never terminate the encode.
        $index = array_search('-t', $args, true);
        $this->assertNotFalse($index, 'Missing explicit -t duration bound');
        $this->assertSame('1800.000', $args[$index + 1]);
        $this->assertContains('-shortest', $args);
    }

    #[Test]
    public function the_filter_graph_contains_the_waveform(): void
    {
        $graph = $this->filterGraph($this->buildArgs());

        $this->assertStringContainsString('showwaves=s=640x150', $graph);
        $this->assertStringContainsString('mode=cline', $graph);
        $this->assertStringContainsString('colors=red', $graph);
        // shortest=1 is what actually ends the composite.
        $this->assertStringContainsString('overlay=320:540:shortest=1', $graph);
    }

    #[Test]
    public function the_filter_graph_contains_every_template_layer(): void
    {
        $graph = $this->filterGraph($this->buildArgs());

        // Background cover+crop, readability overlay, branding.
        $this->assertStringContainsString('force_original_aspect_ratio=increase', $graph);
        $this->assertStringContainsString('crop=1280:720', $graph);
        $this->assertStringContainsString('overlay=1022:42', $graph);

        // One drawtext per text run: #1 #2 #3 #4 #6 #7×2 #8 = 8.
        $this->assertSame(8, substr_count($graph, 'drawtext='));
    }

    #[Test]
    public function user_text_is_passed_by_file_and_never_interpolated(): void
    {
        $args = $this->buildArgs([
            'primary_title' => "Bahaya ':; Riya",
        ]);
        $graph = $this->filterGraph($args);

        // Every drawtext reads its run from a file — none uses an inline
        // `text=` value, which is what would expose the shell/filter parser
        // to user input.
        $this->assertSame(
            substr_count($graph, 'drawtext='),
            substr_count($graph, ':textfile='),
        );

        // And the literal user string appears nowhere in the command at all.
        $this->assertStringNotContainsString('Riya', implode(' ', $args));
        $this->assertStringNotContainsString('Bahaya', implode(' ', $args));
    }

    #[Test]
    public function drawtext_expansion_is_disabled_so_titles_stay_literal(): void
    {
        $graph = $this->filterGraph($this->buildArgs());

        // Without this, a title containing %{pts} would be interpreted.
        $this->assertSame(
            substr_count($graph, 'drawtext='),
            substr_count($graph, 'expansion=none'),
        );
    }

    #[Test]
    public function a_null_part_number_draws_one_fewer_layer(): void
    {
        $withPart = substr_count($this->filterGraph($this->buildArgs()), 'drawtext=');
        $without = substr_count($this->filterGraph($this->buildArgs(['part_number' => null])), 'drawtext=');

        $this->assertSame($withPart - 1, $without);
    }

    #[Test]
    public function loudness_normalisation_is_off_by_default(): void
    {
        $this->assertStringNotContainsString('loudnorm', $this->filterGraph($this->buildArgs()));
    }

    #[Test]
    public function the_audio_is_decoded_and_resampled_rather_than_stream_copied(): void
    {
        $args = $this->buildArgs();
        $graph = $this->filterGraph($args);

        $this->assertStringContainsString('aresample=48000', $graph);
        $this->assertNotContains('copy', $args);
    }

    // ── Delivery ────────────────────────────────────────────────────────────

    #[Test]
    public function the_video_endpoint_refuses_a_project_that_has_not_rendered(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/video")->assertStatus(404);
        $this->getJson("/api/v1/content-projects/{$project->uuid}/download")->assertStatus(404);
    }

    #[Test]
    public function the_rendered_video_is_only_served_to_its_owner(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $project = $this->renderableProject($owner);

        $path = "content/{$project->uuid}/renders/output.mp4";
        Storage::disk('local')->put($path, 'fake-mp4-bytes');
        $project->forceFill([
            'render_status' => RenderStatus::Rendered,
            'output_path' => $path,
        ])->save();

        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/v1/content-projects/{$project->uuid}/video")->assertStatus(404);

        Sanctum::actingAs($owner);
        $this->get("/api/v1/content-projects/{$project->uuid}/video")->assertOk();
    }
}
