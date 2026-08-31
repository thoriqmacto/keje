<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\ContentProject;
use App\Models\User;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;

/**
 * Edits a video that already exists on YouTube, in place.
 *
 * Most corrections are not corrections to the video at all — a typo in the
 * description, the wrong privacy, a forgotten tag. YouTube supports editing
 * every one of those on the existing id, and re-uploading for them would throw
 * away the URL, the view count and every comment for no reason.
 *
 * Kept out of YouTubeService on purpose. That class uploads; this one edits.
 * They share the intended-metadata builder and nothing else, and neither can
 * reach the other's API call — which is what guarantees that a metadata retry
 * cannot end up at videos.insert.
 *
 * ── The part-replacement trap ────────────────────────────────────────────
 *
 * videos.update does not patch. Every part named in `part` is replaced
 * wholesale by what the request carries, and a field omitted from a part that
 * *is* named is not "left alone" — it is erased. Sending a snippet with only a
 * description therefore blanks the title and the category, and YouTube rejects
 * the request outright because both are required.
 *
 * So this reads the video first (videos.list, one quota unit) and sends a
 * complete resource built from the current remote values with Keje's intent
 * layered over them. The read is not an optimisation to skip.
 */
class YouTubeVideoUpdater
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly YouTubeMetadataBuilder $metadata,
    ) {}

    /**
     * Push the project's current publishing metadata onto its existing video.
     *
     * @return array{id:string, privacy_status:string, publish_at:?string, title:string}
     */
    public function update(ContentProject $project, string $videoId): array
    {
        $intent = $this->metadata->for($project);
        $this->metadata->assertScheduleIsFuture($intent['publish_at']);

        $api = new YouTube($this->clients->forUser($project->user, GoogleService::YouTube));
        $remote = $this->read($api, $videoId);

        $snippet = new VideoSnippet;
        // Required by the API on every snippet write, and the two fields most
        // easily destroyed by a partial update.
        $snippet->setTitle($intent['title']);
        $snippet->setCategoryId($intent['category_id']);
        $snippet->setDescription($intent['description']);
        $snippet->setTags($intent['tags']);

        // Only ever set when Keje has an opinion. Sending null would clear a
        // language somebody set in YouTube Studio, which is not a field this
        // app manages and therefore not one it may erase.
        $language = $intent['default_language'] ?? $remote['default_language'];

        if (filled($language)) {
            $snippet->setDefaultLanguage($language);
        }

        $status = new VideoStatus;
        $status->setPrivacyStatus($intent['privacy_status']);
        $status->setSelfDeclaredMadeForKids($intent['made_for_kids']);

        if ($intent['publish_at'] !== null) {
            $status->setPublishAt($intent['publish_at']->utc()->toRfc3339String());
        }

        // Carried over untouched: YouTube requires it on a status write and it
        // is not something Keje manages.
        if (filled($remote['license'])) {
            $status->setLicense($remote['license']);
        }

        $video = new Video;
        $video->setId($videoId);
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $updated = $api->videos->update('snippet,status', $video);

        return [
            'id' => (string) $updated->getId(),
            'privacy_status' => (string) $updated->getStatus()?->getPrivacyStatus(),
            'publish_at' => $updated->getStatus()?->getPublishAt(),
            'title' => (string) $updated->getSnippet()?->getTitle(),
        ];
    }

    /**
     * Set a video's privacy and nothing else.
     *
     * Two callers, both in the replacement workflow: hiding the old video when
     * the user chose to keep it, and lifting the new one out of private once
     * the old one is gone. Both must leave the description and title exactly
     * as they are, so this rebuilds the snippet from the remote read rather
     * than from Keje's intent — this is not the place where metadata is
     * reconciled.
     *
     * @return array{id:string, privacy_status:string, publish_at:?string}
     */
    public function setPrivacy(
        User $user,
        string $videoId,
        string $privacyStatus,
        ?\DateTimeInterface $publishAt = null,
    ): array {
        $api = new YouTube($this->clients->forUser($user, GoogleService::YouTube));
        $remote = $this->read($api, $videoId);

        $snippet = new VideoSnippet;
        $snippet->setTitle($remote['title']);
        $snippet->setCategoryId($remote['category_id']);
        $snippet->setDescription($remote['description']);
        $snippet->setTags($remote['tags']);

        if (filled($remote['default_language'])) {
            $snippet->setDefaultLanguage($remote['default_language']);
        }

        $status = new VideoStatus;
        $status->setPrivacyStatus($privacyStatus);
        $status->setSelfDeclaredMadeForKids($remote['made_for_kids']);

        if ($publishAt !== null) {
            $status->setPublishAt(
                \Illuminate\Support\Carbon::instance(
                    \DateTimeImmutable::createFromInterface($publishAt),
                )->utc()->toRfc3339String(),
            );
        }

        if (filled($remote['license'])) {
            $status->setLicense($remote['license']);
        }

        $video = new Video;
        $video->setId($videoId);
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $updated = $api->videos->update('snippet,status', $video);

        return [
            'id' => (string) $updated->getId(),
            'privacy_status' => (string) $updated->getStatus()?->getPrivacyStatus(),
            'publish_at' => $updated->getStatus()?->getPublishAt(),
        ];
    }

    /**
     * Permanently remove a video. There is no undo on YouTube's side.
     *
     * A video that is already gone is reported as YouTubeVideoMissingException
     * rather than a generic failure. The caller's goal is for the video to
     * stop existing, and it has — treating Google's 404 as an error would
     * strand a replacement forever on a step that can never now succeed.
     */
    public function delete(User $user, string $videoId): void
    {
        $api = new YouTube($this->clients->forUser($user, GoogleService::YouTube));

        try {
            $api->videos->delete($videoId);
        } catch (\Google\Service\Exception $e) {
            if ($this->isMissing($e)) {
                throw new YouTubeVideoMissingException(
                    "The video {$videoId} no longer exists on the connected channel.",
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /** Google's several ways of saying "that video is not there". */
    private function isMissing(\Google\Service\Exception $e): bool
    {
        if ($e->getCode() === 404) {
            return true;
        }

        foreach ((array) $e->getErrors() as $error) {
            if (is_array($error) && in_array($error['reason'] ?? null, ['videoNotFound', 'notFound'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The video's current mutable fields, so an update can be complete.
     *
     * @return array{title:string, description:string, tags:list<string>, category_id:string, default_language:?string, made_for_kids:bool, license:?string, privacy_status:?string}
     */
    private function read(YouTube $api, string $videoId): array
    {
        $response = $api->videos->listVideos('snippet,status', ['id' => $videoId]);
        $items = $response->getItems();

        if ($items === []) {
            // Not a generic failure: the video is gone, and every caller here
            // needs to treat that differently from a transient API error.
            throw new YouTubeVideoMissingException(
                "The video {$videoId} no longer exists on the connected channel.",
            );
        }

        $video = $items[0];
        $snippet = $video->getSnippet();
        $status = $video->getStatus();

        return [
            'title' => (string) $snippet?->getTitle(),
            'description' => (string) $snippet?->getDescription(),
            'tags' => array_values((array) ($snippet?->getTags() ?? [])),
            'category_id' => (string) ($snippet?->getCategoryId()
                ?: config('services.youtube.default_category_id')),
            'default_language' => $snippet?->getDefaultLanguage(),
            'made_for_kids' => (bool) $status?->getSelfDeclaredMadeForKids(),
            'license' => $status?->getLicense(),
            'privacy_status' => $status?->getPrivacyStatus(),
        ];
    }
}
