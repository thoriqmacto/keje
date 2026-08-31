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
        private readonly YouTubeMetadataBuilder $metadata,
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
     * @param  ?string  $privacyOverride  forces the uploaded privacy regardless
     *                                    of what the project intends. The
     *                                    replacement workflow uploads private
     *                                    unconditionally, so a corrected video
     *                                    is never publicly visible alongside
     *                                    the one it is about to replace.
     * @return array{id:string, url:string, privacy_status:string, publish_at:?string, title:string}
     */
    public function upload(
        User $user,
        ContentProject $project,
        string $absolutePath,
        ?callable $onProgress = null,
        ?string $privacyOverride = null,
    ): array {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The rendered video is no longer available to upload.');
        }

        $this->assertExpectedChannel($user);

        // One shared derivation for upload, in-place update and replacement.
        // Three separate ones would drift, and the drift would first show up
        // as a correction quietly rewriting a title nobody touched.
        $intent = $this->metadata->for($project, $privacyOverride);
        $this->metadata->assertScheduleIsFuture($intent['publish_at']);

        // A replacement is uploaded private and scheduled later, once the old
        // video is gone. Carrying the schedule into the upload would let
        // YouTube publish it while the video it replaces is still up.
        $publishAt = $privacyOverride === null ? $intent['publish_at'] : null;

        $client = $this->clients->forUser($user, GoogleService::YouTube);
        $youtube = new YouTube($client);

        $snippet = new VideoSnippet;
        $snippet->setTitle($intent['title']);
        $snippet->setDescription($intent['description']);
        $snippet->setCategoryId($intent['category_id']);

        if ($intent['tags'] !== []) {
            $snippet->setTags($intent['tags']);
        }

        if ($intent['default_language'] !== null) {
            $snippet->setDefaultLanguage($intent['default_language']);
        }

        $status = new VideoStatus;
        $status->setPrivacyStatus($intent['privacy_status']);
        $status->setSelfDeclaredMadeForKids($intent['made_for_kids']);

        if ($publishAt !== null) {
            // YouTube requires RFC 3339 in UTC.
            $status->setPublishAt($publishAt->utc()->toRfc3339String());
        }

        $video = new Video;
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $client->setDefer(true);

        $request = $youtube->videos->insert('snippet,status', $video, [
            'notifySubscribers' => $intent['notify_subscribers'],
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
            'title' => $intent['title'],
        ];
    }

    /**
     * Add an already-uploaded video to a playlist.
     *
     * Returns the playlistItem id, which is the proof of membership worth
     * storing. Errors are deliberately allowed to escape: swallowing them
     * here is what made playlist failures invisible. The caller
     * (YouTubePlaylistAssigner) decides what each failure means — an
     * already-present video is success, a deleted playlist is not — and it is
     * the caller that keeps the upload itself successful either way.
     *
     * Requires the youtube.force-ssl scope; .upload and .readonly do not
     * permit writing to a playlist.
     */
    public function addToPlaylist(User $user, string $playlistId, string $videoId): string
    {
        $youtube = new YouTube($this->clients->forUser($user, GoogleService::YouTube));

        $resource = new YouTube\PlaylistItem;
        $snippet = new YouTube\PlaylistItemSnippet;
        $snippet->setPlaylistId($playlistId);

        $resourceId = new YouTube\ResourceId;
        $resourceId->setKind('youtube#video');
        $resourceId->setVideoId($videoId);
        $snippet->setResourceId($resourceId);
        $resource->setSnippet($snippet);

        return (string) $youtube->playlistItems->insert('snippet', $resource)->getId();
    }

    private function chunkSize(int $requested): int
    {
        $unit = 256 * 1024;

        return max($unit, (int) (floor($requested / $unit) * $unit));
    }
}
