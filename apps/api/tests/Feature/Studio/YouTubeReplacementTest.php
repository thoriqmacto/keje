<?php

namespace Tests\Feature\Studio;

use App\Enums\GoogleService;
use App\Enums\OldVideoDisposition;
use App\Enums\ReplacementStatus;
use App\Enums\YouTubeStatus;
use App\Jobs\AdvanceYouTubeReplacementJob;
use App\Models\ContentProject;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Models\YouTubePublication;
use App\Models\YouTubeReplacement;
use App\Services\Google\GoogleClientFactory;
use App\Services\Media\RenderInputFingerprint;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Replacing the video file behind a published lecture.
 *
 * YouTube has no way to swap the file behind an existing id, so correcting
 * anything drawn on the frames means a new video and a new URL. The obvious
 * implementation — delete, then upload — loses the lecture outright if the
 * upload then fails, so the order is inverted and these tests are mostly about
 * proving that inversion holds under every failure.
 *
 * The assertions are on the HTTP calls themselves, in order. A "did the status
 * change" test would pass just as happily against an implementation that
 * deleted first.
 */
class YouTubeReplacementTest extends TestCase
{
    use RefreshDatabase;

    private \ArrayObject $requests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = new \ArrayObject;
        Storage::fake('local');

        config([
            'services.google.clients.youtube.client_id' => 'youtube-client-id',
            'services.google.clients.youtube.client_secret' => 'youtube-secret',
            'services.google.clients.youtube.redirect_uri' => 'http://localhost:8000/api/v1/integrations/youtube/callback',
            'services.youtube.default_category_id' => '27',
            'services.youtube.chunk_size' => 262144,
            // Off by default so the prune assertions in this file are about
            // the replacement, not the window.
            'media.retention.correction_window_days' => 0,
        ]);
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = []): void
    {
        GoogleConnection::create([
            'user_id' => $user->id,
            'service' => GoogleService::YouTube,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => $scopes === [] ? GoogleService::YouTube->scopes() : $scopes,
            'connected_at' => now(),
        ]);
    }

    /** @param list<Response> $responses */
    private function fakeGoogle(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $http = new GuzzleClient(['handler' => $stack]);

        $this->app->bind(GoogleClientFactory::class, fn () => new class($http) extends GoogleClientFactory
        {
            public function __construct(private readonly GuzzleClient $http) {}

            public function base(GoogleService $service): \Google\Client
            {
                $client = parent::base($service);
                $client->setHttpClient($this->http);

                return $client;
            }
        });
    }

    /**
     * A published project whose current render differs from the published one.
     *
     * The precondition for a replacement: the frames changed, so editing the
     * description would not fix anything.
     */
    private function correctable(User $user, array $attributes = []): ContentProject
    {
        $project = ContentProject::factory()->withMedia()->create([
            'user_id' => $user->id,
            'youtube_video_id' => 'OLD123',
            'youtube_url' => 'https://www.youtube.com/watch?v=OLD123',
            'youtube_status' => YouTubeStatus::Published,
            'youtube_uploaded_at' => now()->subDay(),
            'drive_status' => \App\Enums\DriveStatus::Uploaded,
            'drive_file_id' => 'drive-1',
            ...$attributes,
        ]);

        $path = $project->storageDirectory().'/renders/output.mp4';
        Storage::disk('local')->put($path, str_repeat('m', 2048));

        $project->forceFill([
            'output_path' => $path,
            'render_status' => \App\Enums\RenderStatus::Rendered,
            // The current render is fresh...
            'last_render_input_hash' => app(RenderInputFingerprint::class)->for($project),
            // ...and the published video came from a different, earlier one.
            'youtube_render_input_hash' => str_repeat('0', 64),
        ])->save();

        return $project->refresh();
    }

    // ── Response fixtures ───────────────────────────────────────────────────

    /**
     * A resumable videos.insert is two round trips, not one.
     *
     * The POST initiates and answers with a Location; the file itself then
     * goes to that URL as a PUT, and only the final chunk's response carries
     * the video resource. Mocking it as a single call passes an upload that
     * never returns an id, which is a subtler failure than it sounds.
     *
     * @return list<Response>
     */
    private function uploadAccepted(): array
    {
        return [
            new Response(200, [
                'Location' => 'https://upload.googleapis.com/resume?upload_id=xyz',
            ], ''),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'NEW456',
                'status' => ['privacyStatus' => 'private'],
            ])),
        ];
    }

    private function remoteVideo(string $id = 'NEW456', array $status = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'items' => [[
                'id' => $id,
                'snippet' => [
                    'title' => 'Keutamaan Lapar',
                    'description' => 'Description',
                    'categoryId' => '27',
                    'tags' => [],
                ],
                'status' => array_merge([
                    'privacyStatus' => 'private',
                    'uploadStatus' => 'processed',
                    'license' => 'youtube',
                ], $status),
            ]],
        ]));
    }

    private function videoUpdated(array $status = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'NEW456',
            'snippet' => ['title' => 'Keutamaan Lapar', 'categoryId' => '27'],
            'status' => array_merge(['privacyStatus' => 'public'], $status),
        ]));
    }

    private function deleted(): Response
    {
        return new Response(204);
    }

    /** "METHOD /path" for every request the transport saw, in order. */
    private function calls(): array
    {
        return array_map(
            static fn (array $t): string => $t['request']->getMethod().' '.$t['request']->getUri()->getPath(),
            iterator_to_array($this->requests),
        );
    }

    private function countMatching(string $needle): int
    {
        return count(array_filter(
            $this->calls(),
            static fn (string $c): bool => str_contains($c, $needle),
        ));
    }

    /** Drain the chained job by hand, as a worker would. */
    private function drain(YouTubeReplacement $replacement, int $max = 4): void
    {
        for ($i = 0; $i < $max; $i++) {
            $fresh = $replacement->refresh();

            if ($fresh->status->isTerminal()
                || $fresh->status === ReplacementStatus::Failed
                || $fresh->nextStage() === null) {
                return;
            }

            app(AdvanceYouTubeReplacementJob::class, ['replacementId' => $replacement->id])
                ->handle(
                    app(\App\Services\Google\YouTubeService::class),
                    app(\App\Services\Google\YouTubeVideoUpdater::class),
                    app(\App\Services\Google\YouTubeReplacementService::class),
                    app(\App\Services\Google\YouTubePublicationRecorder::class),
                    app(\App\Services\Google\YouTubePlaylistAssigner::class),
                    app(\App\Services\Google\GoogleErrorTranslator::class),
                );
        }
    }

    // ── The happy path, in order ────────────────────────────────────────────

    #[Test]
    public function the_replacement_uploads_before_the_old_video_is_deleted(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),   // videos.insert → NEW456, private
            $this->deleted(),          // videos.delete  → OLD123
            $this->remoteVideo(),      // videos.list    (before the update)
            $this->videoUpdated(),     // videos.update  → NEW456 public
            $this->remoteVideo('NEW456', ['privacyStatus' => 'public']), // sync
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)
            ->start($project, OldVideoDisposition::Delete);

        $this->drain($replacement);

        $calls = $this->calls();

        // The ordering that makes this safe. Reversed, a failed upload after a
        // successful delete would leave the channel with no lecture at all.
        $uploadIndex = null;
        $deleteIndex = null;

        foreach ($calls as $i => $call) {
            if ($uploadIndex === null && str_contains($call, 'upload')) {
                $uploadIndex = $i;
            }

            if ($deleteIndex === null && str_starts_with($call, 'DELETE')) {
                $deleteIndex = $i;
            }
        }

        $this->assertNotNull($uploadIndex, 'The replacement should have uploaded.');
        $this->assertNotNull($deleteIndex, 'The old video should have been deleted.');
        $this->assertLessThan(
            $deleteIndex,
            $uploadIndex,
            'The corrected video must exist on YouTube before the old one is deleted.',
        );

        $replacement->refresh();
        $this->assertSame(ReplacementStatus::Completed, $replacement->status);
        $this->assertSame('NEW456', $replacement->new_video_id);
        // The lock is released only at a terminal state.
        $this->assertNull($replacement->active_key);

        $project->refresh();
        $this->assertSame('NEW456', $project->youtube_video_id);
        $this->assertSame('https://www.youtube.com/watch?v=NEW456', $project->youtube_url);
        // The replacement now answers for the current render.
        $this->assertSame($project->last_render_input_hash, $project->youtube_render_input_hash);
    }

    #[Test]
    public function the_replacement_is_uploaded_private_whatever_the_project_wants(): void
    {
        // Only the stage under test may run: the queue is sync here, so the
        // chained dispatch would otherwise carry straight on to the next one.
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        // The project wants to be public — but two visible copies must never
        // coexist, so the upload itself is private regardless.
        $project = $this->correctable($user, [
            'youtube_metadata' => ['privacy_status' => 'public'],
        ]);

        $this->fakeGoogle($this->uploadAccepted());

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);

        // Only the upload stage.
        app(AdvanceYouTubeReplacementJob::class, ['replacementId' => $replacement->id])->handle(
            app(\App\Services\Google\YouTubeService::class),
            app(\App\Services\Google\YouTubeVideoUpdater::class),
            app(\App\Services\Google\YouTubeReplacementService::class),
            app(\App\Services\Google\YouTubePublicationRecorder::class),
            app(\App\Services\Google\YouTubePlaylistAssigner::class),
            app(\App\Services\Google\GoogleErrorTranslator::class),
        );

        $body = null;

        foreach ($this->requests as $transaction) {
            $decoded = json_decode((string) $transaction['request']->getBody(), true);

            if (isset($decoded['status']['privacyStatus'])) {
                $body = $decoded;
                break;
            }
        }

        $this->assertNotNull($body, 'Expected a videos.insert body.');
        $this->assertSame('private', $body['status']['privacyStatus']);
    }

    #[Test]
    public function while_the_replacement_uploads_the_old_video_is_still_current(): void
    {
        // Only the stage under test may run: the queue is sync here, so the
        // chained dispatch would otherwise carry straight on to the next one.
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle($this->uploadAccepted());

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);

        app(AdvanceYouTubeReplacementJob::class, ['replacementId' => $replacement->id])->handle(
            app(\App\Services\Google\YouTubeService::class),
            app(\App\Services\Google\YouTubeVideoUpdater::class),
            app(\App\Services\Google\YouTubeReplacementService::class),
            app(\App\Services\Google\YouTubePublicationRecorder::class),
            app(\App\Services\Google\YouTubePlaylistAssigner::class),
            app(\App\Services\Google\GoogleErrorTranslator::class),
        );

        // The corrected video exists and is private; the project must still
        // point at the one the world can see. Pointing it at NEW456 here would
        // send every "open on YouTube" click to an unwatchable video.
        $this->assertSame('OLD123', $project->refresh()->youtube_video_id);
        $this->assertTrue($replacement->refresh()->oldStillCurrent());
    }

    // ── Failure at each step ────────────────────────────────────────────────

    #[Test]
    public function a_failed_upload_leaves_the_published_video_untouched(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([new Response(500, [], 'upstream exploded')]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        $replacement->refresh();
        $this->assertSame(ReplacementStatus::Failed, $replacement->status);
        $this->assertNull($replacement->new_video_id);

        // The reason upload-first exists.
        $this->assertSame(0, $this->countMatching('DELETE'));
        $this->assertSame('OLD123', $project->refresh()->youtube_video_id);
        $this->assertSame(YouTubeStatus::Published, $project->youtube_status);
    }

    #[Test]
    public function a_failed_delete_keeps_the_old_video_current_and_never_re_uploads(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            new Response(403, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['errors' => [['reason' => 'forbidden']], 'message' => 'Forbidden'],
            ])),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        $replacement->refresh();
        $this->assertSame(ReplacementStatus::Failed, $replacement->status);

        // The corrected video is safe and private; the published one is
        // untouched. Both facts must survive, and the user is told both.
        $this->assertSame('NEW456', $replacement->new_video_id);
        $this->assertSame('OLD123', $project->refresh()->youtube_video_id);
        $this->assertStringContainsString('has not changed', (string) $replacement->error);

        // Exactly one upload, ever.
        $this->assertSame(1, $this->countMatching('upload'));
    }

    #[Test]
    public function retrying_a_failed_delete_does_not_upload_again(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            new Response(403, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['errors' => [['reason' => 'forbidden']], 'message' => 'Forbidden'],
            ])),
            // The retry: delete succeeds this time, then finalisation.
            $this->deleted(),
            $this->remoteVideo(),
            $this->videoUpdated(),
            $this->remoteVideo('NEW456', ['privacyStatus' => 'public']),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        $this->assertSame(ReplacementStatus::Failed, $replacement->refresh()->status);

        // Retry resumes from the facts, not from the beginning.
        app(\App\Services\Google\YouTubeReplacementService::class)->retry($replacement->refresh());
        $this->drain($replacement);

        $this->assertSame(ReplacementStatus::Completed, $replacement->refresh()->status);
        // The whole point of resuming from persisted state.
        $this->assertSame(1, $this->countMatching('upload'));
        $this->assertSame('NEW456', $project->refresh()->youtube_video_id);
    }

    #[Test]
    public function a_failed_finalize_keeps_the_new_video_authoritative(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            $this->deleted(),
            // Finalisation blows up on the read.
            new Response(500, [], 'boom'),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        $replacement->refresh();
        $this->assertSame(ReplacementStatus::Failed, $replacement->status);
        $this->assertNotNull($replacement->old_disposed_at);

        // The old video is gone, so the new one is the project's video even
        // though its final settings never landed. Anything else would leave
        // the project pointing at a deleted video.
        $this->assertSame('NEW456', $project->refresh()->youtube_video_id);
        $this->assertSame(1, $this->countMatching('upload'));
    }

    #[Test]
    public function a_video_already_gone_counts_as_disposed(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            // Someone deleted OLD123 from YouTube Studio in the meantime.
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['errors' => [['reason' => 'videoNotFound']], 'message' => 'Not found'],
            ])),
            $this->remoteVideo(),
            $this->videoUpdated(),
            $this->remoteVideo('NEW456', ['privacyStatus' => 'public']),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        // The goal of the step was for that video to stop existing. It has.
        // Failing here would strand the replacement on a step that can never
        // succeed.
        $this->assertSame(ReplacementStatus::Completed, $replacement->refresh()->status);
        $this->assertSame('NEW456', $project->refresh()->youtube_video_id);
    }

    // ── Concurrency ─────────────────────────────────────────────────────────

    #[Test]
    public function two_replacement_requests_produce_one_workflow(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(202);

        // The double click.
        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(409);

        $this->assertSame(1, YouTubeReplacement::where('content_project_id', $project->id)->count());
        Queue::assertPushed(AdvanceYouTubeReplacementJob::class, 1);
    }

    #[Test]
    public function a_normal_upload_is_blocked_while_a_replacement_runs(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(202);

        // Two writers on youtube_video_id is how a replacement ends up
        // pointing at the video it was replacing.
        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube")
            ->assertStatus(409);
    }

    #[Test]
    public function a_failed_replacement_still_holds_the_lock(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            new Response(403, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['errors' => [['reason' => 'forbidden']], 'message' => 'Forbidden'],
            ])),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        $this->assertSame(ReplacementStatus::Failed, $replacement->refresh()->status);
        // Still holding it: a private video is sitting on the channel, and a
        // second replacement on top of it would add a third copy.
        $this->assertNotNull($replacement->active_key);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(409);
    }

    // ── Preconditions ───────────────────────────────────────────────────────

    #[Test]
    public function a_stale_render_cannot_be_used_as_a_replacement(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        // Edited since the render: uploading now would replace one wrong video
        // with another.
        $project->forceFill(['subtitle' => 'A subtitle typed after rendering'])->save();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.replacement.0',
                'You changed the project but have not rendered the corrected version yet.',
            );
    }

    #[Test]
    public function a_render_matching_the_published_video_offers_no_replacement(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        // The published video came from exactly this render. Replacing it
        // would delete a video and upload an identical one.
        $project->forceFill([
            'youtube_render_input_hash' => $project->last_render_input_hash,
        ])->save();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(422);
    }

    #[Test]
    public function a_connection_without_force_ssl_cannot_replace(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user, [
            GoogleService::SCOPE_YOUTUBE_UPLOAD,
            GoogleService::SCOPE_YOUTUBE_READONLY,
        ]);
        $project = $this->correctable($user);

        // Checked before anything is uploaded: discovering it afterwards would
        // strand a private copy on the channel.
        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.replacement.0',
                'Reconnect YouTube to allow Keje to remove the old video during a replacement.',
            );

        $this->assertSame(0, count($this->calls()));
    }

    #[Test]
    public function a_project_whose_media_was_pruned_says_so(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        // Backed up and cleaned: nothing left to re-render from.
        $project->forceFill([
            'output_path' => null,
            'source_audio_path' => null,
            'background_image_path' => null,
        ])->save();

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.replacement.0',
                'The source media was cleaned from this server after the Drive backup. Upload the audio and artwork again before re-rendering.',
            );
    }

    // ── Cancellation ────────────────────────────────────────────────────────

    #[Test]
    public function cancelling_before_disposal_deletes_the_temporary_copy(): void
    {
        // Only the stage under test may run: the queue is sync here, so the
        // chained dispatch would otherwise carry straight on to the next one.
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            $this->deleted(), // the temporary NEW456 being cleaned up
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);

        app(AdvanceYouTubeReplacementJob::class, ['replacementId' => $replacement->id])->handle(
            app(\App\Services\Google\YouTubeService::class),
            app(\App\Services\Google\YouTubeVideoUpdater::class),
            app(\App\Services\Google\YouTubeReplacementService::class),
            app(\App\Services\Google\YouTubePublicationRecorder::class),
            app(\App\Services\Google\YouTubePlaylistAssigner::class),
            app(\App\Services\Google\GoogleErrorTranslator::class),
        );

        app(\App\Services\Google\YouTubeReplacementService::class)->cancel($replacement->refresh());

        app(AdvanceYouTubeReplacementJob::class, [
            'replacementId' => $replacement->id,
            'cancel' => true,
        ])->handle(
            app(\App\Services\Google\YouTubeService::class),
            app(\App\Services\Google\YouTubeVideoUpdater::class),
            app(\App\Services\Google\YouTubeReplacementService::class),
            app(\App\Services\Google\YouTubePublicationRecorder::class),
            app(\App\Services\Google\YouTubePlaylistAssigner::class),
            app(\App\Services\Google\GoogleErrorTranslator::class),
        );

        $replacement->refresh();
        $this->assertSame(ReplacementStatus::Cancelled, $replacement->status);
        $this->assertNull($replacement->active_key);

        // Back to where it started.
        $this->assertSame('OLD123', $project->refresh()->youtube_video_id);
    }

    #[Test]
    public function cancelling_after_disposal_is_refused(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $replacement = YouTubeReplacement::create([
            'content_project_id' => $project->id,
            'user_id' => $user->id,
            'status' => ReplacementStatus::Failed,
            'active_key' => $project->id,
            'old_video_id' => 'OLD123',
            'new_video_id' => 'NEW456',
            // The old video is already gone. There is nothing to go back to.
            'old_disposed_at' => now(),
            'old_disposition' => OldVideoDisposition::Delete,
        ]);

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement/cancel")
            ->assertStatus(409);

        $this->assertSame(ReplacementStatus::Failed, $replacement->refresh()->status);
    }

    // ── History ─────────────────────────────────────────────────────────────

    #[Test]
    public function history_keeps_the_replaced_video_on_the_record(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            $this->deleted(),
            $this->remoteVideo(),
            $this->videoUpdated(),
            $this->remoteVideo('NEW456', ['privacyStatus' => 'public']),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)->start($project);
        $this->drain($replacement);

        $publications = YouTubePublication::where('content_project_id', $project->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $publications);

        [$old, $new] = [$publications[0], $publications[1]];

        // The old URL is the only record of a link that may still be shared.
        $this->assertSame('OLD123', $old->youtube_video_id);
        $this->assertNotNull($old->replaced_at);
        $this->assertSame('deleted', $old->disposition);
        $this->assertFalse($old->survivesOnYouTube());

        $this->assertSame('NEW456', $new->youtube_video_id);
        $this->assertTrue($new->isCurrent());
        $this->assertSame($old->id, $new->replacement_of_id);
    }

    #[Test]
    public function the_history_endpoint_exposes_both_publications(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->getJson("/api/v1/content-projects/{$project->uuid}/youtube/publications")
            ->assertOk()
            // Backfilled on read: this project was published before history
            // existed, and showing nothing would be a lie.
            ->assertJsonPath('data.0.video_id', 'OLD123')
            ->assertJsonPath('data.0.is_current', true);
    }

    // ── Keeping the old video instead of deleting it ────────────────────────

    #[Test]
    public function keeping_the_old_video_sets_it_private_rather_than_deleting(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable($user);

        $this->fakeGoogle([
            ...$this->uploadAccepted(),
            // Disposal by privacy change: read, then update.
            $this->remoteVideo('OLD123', ['privacyStatus' => 'public']),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'OLD123',
                'snippet' => ['title' => 'Keutamaan Lapar', 'categoryId' => '27'],
                'status' => ['privacyStatus' => 'private'],
            ])),
            $this->remoteVideo(),
            $this->videoUpdated(),
            $this->remoteVideo('NEW456', ['privacyStatus' => 'public']),
        ]);

        $replacement = app(\App\Services\Google\YouTubeReplacementService::class)
            ->start($project, OldVideoDisposition::KeepPrivate);

        $this->drain($replacement);

        $this->assertSame(ReplacementStatus::Completed, $replacement->refresh()->status);
        // Nothing was destroyed: the comments and view count survive, hidden.
        $this->assertSame(0, $this->countMatching('DELETE'));

        $old = YouTubePublication::where('youtube_video_id', 'OLD123')->firstOrFail();
        $this->assertSame('kept_private', $old->disposition);
        $this->assertTrue($old->survivesOnYouTube());
    }

    // ── Ownership ───────────────────────────────────────────────────────────

    #[Test]
    public function another_users_project_cannot_be_replaced(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $this->connect($user);
        $project = $this->correctable(User::factory()->create());

        $this->postJson("/api/v1/content-projects/{$project->uuid}/youtube/replacement")
            ->assertNotFound();
    }
}
