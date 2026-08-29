<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

/**
 * Shared validation for the YouTube metadata block.
 *
 * Kept as structured, individually-validated fields rather than a free-form
 * JSON blob so nothing unexpected can reach the YouTube API. Used by both the
 * project store/update requests and the upload request.
 */
class YouTubeMetadataRules
{
    public const PRIVACY_STATUSES = ['private', 'unlisted', 'public'];

    /**
     * @param  string|null  $prefix  dot-path the metadata sits under, or null for top level
     * @return array<string, array<int, mixed>>
     */
    public function rules(?string $prefix = null): array
    {
        $at = static fn (string $field): string => $prefix === null ? $field : "{$prefix}.{$field}";

        return [
            // YouTube's own limits: 100 chars for title, 5000 for description.
            $at('title') => ['nullable', 'string', 'max:100'],
            $at('description') => ['nullable', 'string', 'max:5000'],
            $at('tags') => ['nullable', 'array', 'max:60'],
            $at('tags.*') => ['string', 'max:100'],
            $at('category_id') => ['nullable', 'string', 'max:10'],
            $at('privacy_status') => ['nullable', Rule::in(self::PRIVACY_STATUSES)],
            // Must be in the future; YouTube performs the publication itself.
            $at('publish_at') => ['nullable', 'date', 'after:now'],
            $at('made_for_kids') => ['nullable', 'boolean'],
            $at('notify_subscribers') => ['nullable', 'boolean'],
        ];
    }
}
