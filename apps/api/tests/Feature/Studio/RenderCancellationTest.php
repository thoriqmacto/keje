<?php

namespace Tests\Feature\Studio;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStatus;
use App\Exceptions\Media\RenderCancelledException;
use App\Jobs\RenderContentProjectJob;
use App\Models\ContentProject;
use App\Models\ContentTopic;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Media\FfmpegService;
use App\Services\Media\RenderInputFingerprint;
use App\Services\Media\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stopping a render that is already under way.
 *
 * The complaint this answers: a render is the longest thing Keje does, and the
 * properties it draws are exactly what somebody notices is wrong the moment
 * the encode starts. Until now there was nothing to do about it — a second
 * dispatch is refused while the first is in flight, so a typo in a title meant
 * waiting out a render nobody wanted.
 *
 * Three things are being proved, and they are different in kind:
 *
 *   **The request.** A queued attempt ends immediately; a running one cannot,
 *   because FFmpeg is in another process, so the endpoint promises to stop it
 *   rather than claiming it has.
 *
 *   **The stop.** FfmpegService really kills its child. That is tested against
 *   a real process rather than a mock, because "we called stop()" and "the
 *   process died" are not the same claim and only one of them matters.
 *
 *   **The aftermath.** A cancellation is not a failure: it never retries,
 *   never runs the post-render uploads, and leaves the project immediately
 *   renderable with no error on it.
 */
class RenderCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    // ── The request ─────────────────────────────────────────────────────────

    #[Test]
    public function cancelling_a_queued_render_ends_it_outright(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('data.render.status', 'cancelled');

        $this->assertSame(RenderStatus::Cancelled, $project->refresh()->render_status);
        $this->assertSame(RenderJobStatus::Cancelled, $project->latestRenderJob()->status);
    }

    #[Test]
    public function cancelling_a_running_render_promises_rather_than_claims(): void
    {
        /*
         * The distinction worth an outcome of its own. FFmpeg is encoding in
         * a worker process; a web request cannot reach it. Writing Cancelled
         * here would show a finished render while frames were still being
         * written, so the status stays Rendering and the worker owns the
         * transition.
         */
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();
        $job = $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'started_at' => now(),
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'stopping')
            ->assertJsonPath('data.render.status', 'rendering');

        $this->assertNotNull($job->refresh()->cancel_requested_at);
        $this->assertSame(RenderJobStatus::Running, $job->status);
    }

    #[Test]
    public function a_queued_cancellation_also_leaves_the_flag_for_a_racing_worker(): void
    {
        /*
         * Not belt-and-braces for its own sake. A worker claiming the attempt
         * in the same instant writes Running a fraction after this writes
         * Cancelled, and the status check alone would then let the encode run
         * to completion — the exact waste this feature exists to prevent. The
         * flag is what the worker re-reads mid-encode, so whoever wins the
         * race the render still stops.
         */
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);
        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")->assertStatus(202);

        $this->assertNotNull($project->latestRenderJob()->cancel_requested_at);
    }

    #[Test]
    public function there_is_nothing_to_cancel_once_a_render_has_finished(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Rendered])->save();
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Succeeded,
            'finished_at' => now(),
        ]);

        // 409, not 404: the project is real and the request was well formed,
        // it just arrived a second after the last frame.
        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'nothing_to_cancel');

        $this->assertSame(RenderStatus::Rendered, $project->refresh()->render_status);
    }

    #[Test]
    public function another_users_render_cannot_be_cancelled(): void
    {
        $owner = User::factory()->create();
        $project = $this->renderableProject($owner);
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();
        $project->renderJobs()->create(['status' => RenderJobStatus::Running]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")
            ->assertStatus(404);

        $this->assertNull($project->latestRenderJob()->cancel_requested_at);
        $this->assertSame(RenderStatus::Rendering, $project->refresh()->render_status);
    }

    #[Test]
    public function cancelling_requires_authentication(): void
    {
        $project = $this->renderableProject(User::factory()->create());

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")
            ->assertStatus(401);
    }

    // ── The stop ────────────────────────────────────────────────────────────

    #[Test]
    public function the_ffmpeg_child_process_is_actually_killed(): void
    {
        /*
         * A real process, not a mock. "stop() was called" and "the process
         * died" are different claims, and only the second one saves anybody
         * the two hours of CPU this feature is about. /bin/sh stands in for
         * FFmpeg: it emits the same out_time_us progress lines and would run
         * for twenty seconds if nothing interrupted it.
         */
        $service = new FfmpegService('/bin/sh', timeout: 60);

        $script = 'for i in $(seq 1 200); do echo "out_time_us=$((i*1000000))"; sleep 0.1; done';

        $progress = [];
        $startedAt = microtime(true);

        $result = $service->run(
            arguments: ['-c', $script],
            totalDuration: 200.0,
            onProgress: function (float $fraction) use (&$progress): void {
                $progress[] = $fraction;
            },
            // Consulted every ABORT_CHECK_SECONDS, so this aborts on the
            // first check rather than immediately.
            shouldAbort: fn (): bool => true,
        );

        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue($result['aborted']);

        // It had begun, and it did not run to its natural end.
        $this->assertNotEmpty($progress, 'progress should still flow while polling');
        $this->assertLessThan(
            20.0,
            $elapsed,
            'the script runs for 20s unaided; a real kill returns in a fraction of that',
        );
    }

    #[Test]
    public function a_run_nobody_cancels_is_not_reported_as_aborted(): void
    {
        // The other half of the same claim: the poll loop must not invent an
        // abort, or every ordinary render would land as cancelled.
        $service = new FfmpegService('/bin/sh', timeout: 30);

        $result = $service->run(
            arguments: ['-c', 'echo "out_time_us=500000"; exit 0'],
            totalDuration: 1.0,
            shouldAbort: fn (): bool => false,
        );

        $this->assertFalse($result['aborted']);
        $this->assertSame(0, $result['exit_code']);
    }

    // ── The aftermath ───────────────────────────────────────────────────────

    #[Test]
    public function a_cancelled_encode_is_recorded_as_cancelled_not_failed(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();
        $job = $project->renderJobs()->create(['status' => RenderJobStatus::Running]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldReceive('render')->once()
            ->andThrow(new RenderCancelledException('The render was cancelled.'));

        (new RenderContentProjectJob($project->id, $job->id))
            ->handle($renderer, app(RenderInputFingerprint::class));

        $project->refresh();

        $this->assertSame(RenderStatus::Cancelled, $project->render_status);
        $this->assertSame(RenderJobStatus::Cancelled, $job->refresh()->status);
        $this->assertNotNull($job->finished_at);

        // No error text. A cancellation is a decision, and a red banner about
        // it on the next page load would have somebody hunting for a fault.
        $this->assertNull($project->render_error);
    }

    #[Test]
    public function a_cancelled_render_never_uploads_anything(): void
    {
        /*
         * The consequence that would be expensive to get wrong. Post-render
         * actions are snapshotted at dispatch, so a cancellation that fell
         * through to them would publish a lecture from a render that was
         * deliberately abandoned.
         */
        Queue::fake();

        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $job = $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'post_actions' => ['drive_backup' => true, 'youtube_upload' => true],
        ]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldReceive('render')->once()
            ->andThrow(new RenderCancelledException('The render was cancelled.'));

        (new RenderContentProjectJob($project->id, $job->id))
            ->handle($renderer, app(RenderInputFingerprint::class));

        Queue::assertNothingPushed();

        // Asserted together on purpose: nothing being pushed is also true of
        // an ordinary failure, so without the status this would pass even if
        // cancellations were being recorded as failures.
        $this->assertSame(RenderStatus::Cancelled, $project->refresh()->render_status);
    }

    #[Test]
    public function an_attempt_cancelled_before_a_worker_saw_it_never_encodes(): void
    {
        $user = User::factory()->create();
        $project = $this->renderableProject($user);
        $job = $project->renderJobs()->create([
            'status' => RenderJobStatus::Queued,
            'cancel_requested_at' => now(),
        ]);

        $renderer = Mockery::mock(VideoRenderer::class);
        $renderer->shouldNotReceive('render');

        (new RenderContentProjectJob($project->id, $job->id))
            ->handle($renderer, app(RenderInputFingerprint::class));

        $this->assertSame(RenderStatus::Cancelled, $project->refresh()->render_status);
        $this->assertSame(RenderJobStatus::Cancelled, $job->refresh()->status);
    }

    #[Test]
    public function the_project_can_be_edited_and_rendered_again_straight_away(): void
    {
        /*
         * The point of the whole feature, end to end: stop the render, fix the
         * thing that was wrong, start again. A cancelled project must not be
         * in flight, or the second dispatch would be refused exactly the way
         * it was before any of this existed.
         */
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")->assertStatus(202);
        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")->assertStatus(202);

        $this->assertFalse($project->refresh()->render_status->isInFlight());

        $this->patchJson("/api/v1/content-projects/{$project->uuid}", [
            'primary_title' => 'Keutamaan Lapar',
        ])->assertOk();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render")
            ->assertStatus(202)
            ->assertJsonPath('data.render.status', 'queued');

        $this->assertSame(2, $project->refresh()->renderJobs()->count());
    }

    #[Test]
    public function the_status_endpoint_says_a_stop_is_under_way(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Rendering])->save();
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Running,
            'progress_percent' => 42,
        ]);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.cancel_requested', false);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/render/cancel")->assertStatus(202);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.cancel_requested', true)
            ->assertJsonPath('data.status', 'rendering');
    }

    #[Test]
    public function a_finished_attempt_stops_reporting_a_pending_cancellation(): void
    {
        // cancel_requested_at stays on the row as history. Reporting it after
        // the attempt ended would leave the button stuck on "Stopping…".
        Sanctum::actingAs($user = User::factory()->create());
        $project = $this->renderableProject($user);
        $project->forceFill(['render_status' => RenderStatus::Cancelled])->save();
        $project->renderJobs()->create([
            'status' => RenderJobStatus::Cancelled,
            'cancel_requested_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/render-status")
            ->assertOk()
            ->assertJsonPath('data.cancel_requested', false)
            ->assertJsonPath('data.status', 'cancelled');
    }
}
