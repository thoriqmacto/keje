<?php

namespace App\Services\Google;

use App\Models\ContentProject;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The metadata a project intends to publish, in one place.
 *
 * Three call sites need this and they must not disagree: the first upload, an
 * in-place metadata correction, and a replacement upload. If they each derived
 * the title separately, correcting a description would quietly also rewrite
 * the title — and nobody would notice until the video was live.
 *
 * Nothing here talks to Google. It turns a project into the values Keje
 * intends; assembling them into API resources and deciding what to send is the
 * caller's job, because upload and update have genuinely different rules about
 * what a partial payload means.
 */
class YouTubeMetadataBuilder
{
    /** YouTube truncates past this; better to do it ourselves and predictably. */
    private const TITLE_LIMIT = 100;

    private const DESCRIPTION_LIMIT = 5000;

    /**
     * Everything Keje intends this video to be.
     *
     * @param  ?string  $privacyOverride  forces privacy regardless of intent —
     *                                    a replacement uploads private no matter
     *                                    what the project eventually wants
     * @return array{title:string, description:string, tags:list<string>, category_id:string, default_language:?string, privacy_status:string, publish_at:?Carbon, made_for_kids:bool, notify_subscribers:bool}
     */
    public function for(ContentProject $project, ?string $privacyOverride = null): array
    {
        $metadata = (array) ($project->youtube_metadata ?? []);

        // One reading of the intended schedule, shared with everything that
        // reports it. Parsing it a second time here is how the list and the
        // upload would eventually come to disagree about what was planned.
        $publishAt = $project->plannedPublishAt();

        // A scheduled video is uploaded private and published by YouTube.
        // Asking for a schedule and public at once is contradictory, and
        // YouTube resolves it by ignoring one of them.
        $privacy = $privacyOverride
            ?? ($publishAt !== null ? 'private' : (string) ($metadata['privacy_status'] ?? 'private'));

        return [
            'title' => $this->title($project, $metadata),
            'description' => mb_substr((string) ($metadata['description'] ?? ''), 0, self::DESCRIPTION_LIMIT),
            'tags' => array_values(array_filter(array_map(
                static fn ($tag): string => trim((string) $tag),
                (array) ($metadata['tags'] ?? []),
            ))),
            'category_id' => (string) ($metadata['category_id'] ?? config('services.youtube.default_category_id')),
            'default_language' => filled($metadata['default_language'] ?? null)
                ? (string) $metadata['default_language']
                : null,
            'privacy_status' => $privacy,
            'publish_at' => $publishAt,
            'made_for_kids' => (bool) ($metadata['made_for_kids'] ?? false),
            'notify_subscribers' => (bool) ($metadata['notify_subscribers'] ?? false),
        ];
    }

    /**
     * Refuse a schedule that has already passed.
     *
     * YouTube accepts a past publishAt on some paths and silently never
     * publishes, which presents as a video stuck private forever with nothing
     * to explain it.
     */
    public function assertScheduleIsFuture(?Carbon $publishAt): void
    {
        if ($publishAt !== null && $publishAt->isPast()) {
            throw new RuntimeException('The scheduled publish time is in the past.');
        }
    }

    /** The YouTube title, falling back to the project's own naming. */
    private function title(ContentProject $project, array $metadata): string
    {
        $title = trim((string) ($metadata['title'] ?? ''));

        if ($title !== '') {
            return mb_substr($title, 0, self::TITLE_LIMIT);
        }

        $parts = array_filter([
            $project->primary_title,
            $project->subtitle,
            $project->topic?->name,
            $project->part_number !== null ? "Part {$project->part_number}" : null,
        ]);

        return mb_substr(implode(' | ', $parts) ?: (string) $project->working_title, 0, self::TITLE_LIMIT);
    }
}
