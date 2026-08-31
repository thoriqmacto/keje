<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Enums\YouTubeRemoteStatus;
use App\Enums\YouTubeStatus;
use App\Models\ContentProject;
use App\Models\User;
use Google\Service\YouTube;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What YouTube says about videos we uploaded, now rather than at upload time.
 *
 * A project uploaded with a publishAt stayed "Scheduled" in the studio forever,
 * because the state was captured the moment videos.insert returned and nothing
 * ever asked again. YouTube publishes the video on its own schedule, and people
 * change privacy from the YouTube app; neither event reaches us unless we look.
 *
 * Read-only, always. This never sets privacy, never re-uploads, and never
 * "corrects" YouTube to match a stale local value — if someone made a public
 * video private, that is the truth and Keje's job is to report it.
 *
 * videos.list costs one quota unit and takes up to 50 ids per call, so a whole
 * studio list is one request. search.list, at a hundred units, is never needed:
 * we know the ids.
 */
class YouTubeVideoSyncService
{
    /** The API's own ceiling for an id list. */
    private const BATCH = 50;

    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly GoogleErrorTranslator $errors,
    ) {}

    /**
     * Refresh a batch of projects in as few calls as possible.
     *
     * @param  Collection<int, ContentProject>  $projects
     * @return int how many were updated
     */
    public function syncMany(User $user, Collection $projects): int
    {
        $withVideos = $projects->filter(fn (ContentProject $p): bool => filled($p->youtube_video_id));

        if ($withVideos->isEmpty()) {
            return 0;
        }

        $updated = 0;

        foreach ($withVideos->chunk(self::BATCH) as $chunk) {
            $byId = $chunk->keyBy('youtube_video_id');

            try {
                $videos = $this->fetch($user, $byId->keys()->all());
            } catch (Throwable $e) {
                // Record the failure without discarding what we already knew:
                // a quota error is not evidence that a video changed.
                $this->recordSyncFailure($chunk, $this->errors->translate($e));

                continue;
            }

            foreach ($byId as $videoId => $project) {
                $video = $videos[$videoId] ?? null;

                // Asked for, not returned: deleted, or no longer visible to
                // this account.
                $this->apply($project, $video);
                $updated++;
            }
        }

        return $updated;
    }

    public function sync(ContentProject $project): bool
    {
        if (blank($project->youtube_video_id)) {
            return false;
        }

        return $this->syncMany($project->user, collect([$project])) > 0;
    }

    /**
     * @param  list<string>  $videoIds
     * @return array<string, array<string, mixed>>
     */
    private function fetch(User $user, array $videoIds): array
    {
        $api = new YouTube($this->clients->forUser($user, GoogleService::YouTube));

        // Only the parts that answer the question. status carries privacy,
        // upload state and publishAt; snippet carries the title we display.
        $response = $api->videos->listVideos('snippet,status', ['id' => implode(',', $videoIds)]);

        $out = [];

        foreach ($response->getItems() as $item) {
            $status = $item->getStatus();

            $out[(string) $item->getId()] = [
                'privacy_status' => $status?->getPrivacyStatus(),
                'upload_status' => $status?->getUploadStatus(),
                'publish_at' => $status?->getPublishAt(),
                'title' => $item->getSnippet()?->getTitle(),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $video null = not returned by the API */
    private function apply(ContentProject $project, ?array $video): void
    {
        if ($video === null) {
            $project->forceFill([
                'youtube_remote_status' => YouTubeRemoteStatus::Unavailable->value,
                'youtube_remote_synced_at' => now(),
                'youtube_remote_sync_error' => null,
            ])->save();

            return;
        }

        $remote = YouTubeRemoteStatus::fromVideo(
            $video['privacy_status'] ?? null,
            $video['upload_status'] ?? null,
            $video['publish_at'] ?? null,
        );

        $project->forceFill([
            'youtube_remote_status' => $remote->value,
            'youtube_remote_privacy_status' => $video['privacy_status'] ?? null,
            'youtube_remote_publish_at' => ($video['publish_at'] ?? null) === null
                ? null
                : \Illuminate\Support\Carbon::parse($video['publish_at']),
            'youtube_remote_synced_at' => now(),
            'youtube_remote_sync_error' => null,

            // Keep our own pipeline value honest too, but only ever forward
            // and only between states that mean the same thing. It must never
            // overwrite a Failed upload with something Google said about a
            // different, older video.
            'youtube_status' => $this->pipelineStatus($project, $remote),
        ])->save();
    }

    /**
     * The pipeline status, nudged to agree with reality where they overlap.
     *
     * Only for a project we know uploaded successfully: "our job failed" and
     * "Google says private" are different facts, and one must not erase the
     * other.
     */
    private function pipelineStatus(ContentProject $project, YouTubeRemoteStatus $remote): YouTubeStatus
    {
        if (! $project->youtube_status->hasVideo()) {
            return $project->youtube_status;
        }

        return match ($remote) {
            YouTubeRemoteStatus::Published => YouTubeStatus::Published,
            YouTubeRemoteStatus::Scheduled => YouTubeStatus::Scheduled,
            default => $project->youtube_status,
        };
    }

    /** @param Collection<int, ContentProject> $projects */
    private function recordSyncFailure(Collection $projects, string $message): void
    {
        foreach ($projects as $project) {
            $project->forceFill([
                'youtube_remote_sync_error' => $message,
                // Deliberately not clearing youtube_remote_status: the last
                // known state is better information than none.
            ])->save();
        }
    }
}
