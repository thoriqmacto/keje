<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\ContentProject;
use App\Models\User;
use Google\Http\MediaFileUpload;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use RuntimeException;
use Throwable;

/**
 * Uploads the rendered MP4 to YouTube via videos.insert.
 *
 * Resumable and chunked for the same reason as Drive. Scheduling is expressed
 * the way YouTube expects — private + publishAt — and YouTube performs the
 * publication itself; nothing here polls to flip a video public later.
 */
class YouTubeService
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    /**
     * Refuse to upload when the connected channel is not the expected one.
     *
     * Silently publishing a lecture to the wrong channel is far worse than a
     * failed upload, so this is a hard stop rather than a warning.
     */
    public function assertExpectedChannel(User $user): void
    {
        $connection = $user->googleConnectionFor(GoogleService::YouTube);
        $matches = $connection?->matchesExpectedChannel();

        if ($matches === false) {
            throw new RuntimeException(
                'The connected YouTube channel ('.($connection?->youtube_channel_id ?? 'unknown')
                .') is not the expected channel. Reconnect YouTube with the correct account.',
            );
        }
    }

    /**
     * @param  callable(float):void|null  $onProgress  receives 0..1
     * @return array{id:string, url:string, privacy_status:string, publish_at:?string}
     */
    public function upload(
        User $user,
        ContentProject $project,
        string $absolutePath,
        ?callable $onProgress = null,
    ): array {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The rendered video is no longer available to upload.');
        }

        $this->assertExpectedChannel($user);

        $metadata = (array) ($project->youtube_metadata ?? []);
        $publishAt = filled($metadata['publish_at'] ?? null)
            ? \Illuminate\Support\Carbon::parse($metadata['publish_at'])
            : null;

        if ($publishAt !== null && $publishAt->isPast()) {
            throw new RuntimeException('The scheduled publish time is in the past.');
        }

        $client = $this->clients->forUser($user, GoogleService::YouTube);
        $youtube = new YouTube($client);

        $snippet = new VideoSnippet;
        $snippet->setTitle($this->title($project, $metadata));
        $snippet->setDescription((string) ($metadata['description'] ?? ''));

        if (filled($metadata['tags'] ?? null)) {
            $snippet->setTags(array_values((array) $metadata['tags']));
        }

        $snippet->setCategoryId(
            (string) ($metadata['category_id'] ?? config('services.youtube.default_category_id')),
        );

        $status = new VideoStatus;
        // A scheduled video must be uploaded private; publishAt does the rest.
        $status->setPrivacyStatus(
            $publishAt !== null ? 'private' : (string) ($metadata['privacy_status'] ?? 'private'),
        );
        $status->setSelfDeclaredMadeForKids((bool) ($metadata['made_for_kids'] ?? false));

        if ($publishAt !== null) {
            // YouTube requires RFC 3339 in UTC.
            $status->setPublishAt($publishAt->utc()->toRfc3339String());
        }

        $video = new Video;
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $client->setDefer(true);

        $request = $youtube->videos->insert('snippet,status', $video, [
            'notifySubscribers' => (bool) ($metadata['notify_subscribers'] ?? false),
        ]);

        $chunkSize = $this->chunkSize((int) config('services.youtube.chunk_size'));
        $size = (int) filesize($absolutePath);

        $media = new MediaFileUpload($client, $request, 'video/*', '', true, $chunkSize);
        $media->setFileSize($size);

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            $client->setDefer(false);
            throw new RuntimeException('Could not open the rendered video for upload.');
        }

        try {
            $result = false;
            $uploaded = 0;

            while (! $result && ! feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === false) {
                    throw new RuntimeException('Failed while reading the rendered video.');
                }

                $result = $media->nextChunk($chunk);
                $uploaded += strlen($chunk);

                if ($onProgress !== null && $size > 0) {
                    $onProgress(min(1.0, $uploaded / $size));
                }
            }
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }

        if (! $result instanceof Video) {
            throw new RuntimeException('YouTube did not confirm the upload.');
        }

        $videoId = (string) $result->getId();

        return [
            'id' => $videoId,
            'url' => "https://www.youtube.com/watch?v={$videoId}",
            'privacy_status' => (string) $result->getStatus()?->getPrivacyStatus(),
            'publish_at' => $publishAt?->toIso8601String(),
        ];
    }

    /**
     * Add the uploaded video to the topic's playlist, when one is linked.
     *
     * Best-effort by design: a playlist problem must not turn a successful
     * upload into a failure.
     */
    public function addToPlaylist(User $user, string $playlistId, string $videoId): bool
    {
        try {
            $youtube = new YouTube($this->clients->forUser($user, GoogleService::YouTube));

            $resource = new YouTube\PlaylistItem;
            $snippet = new YouTube\PlaylistItemSnippet;
            $snippet->setPlaylistId($playlistId);

            $resourceId = new YouTube\ResourceId;
            $resourceId->setKind('youtube#video');
            $resourceId->setVideoId($videoId);
            $snippet->setResourceId($resourceId);
            $resource->setSnippet($snippet);

            $youtube->playlistItems->insert('snippet', $resource);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** The YouTube title, falling back to the project's own naming. */
    private function title(ContentProject $project, array $metadata): string
    {
        $title = trim((string) ($metadata['title'] ?? ''));

        if ($title !== '') {
            return mb_substr($title, 0, 100);
        }

        $parts = array_filter([
            $project->primary_title,
            $project->subtitle,
            $project->topic?->name,
            $project->part_number !== null ? "Part {$project->part_number}" : null,
        ]);

        return mb_substr(implode(' | ', $parts) ?: $project->working_title, 0, 100);
    }

    private function chunkSize(int $requested): int
    {
        $unit = 256 * 1024;

        return max($unit, (int) (floor($requested / $unit) * $unit));
    }
}
