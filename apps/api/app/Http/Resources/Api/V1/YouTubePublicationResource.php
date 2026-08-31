<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a project's YouTube history.
 *
 * Matters because replacing a video changes its public URL: someone who
 * shared the old link needs to be able to see that it was replaced, and by
 * what. A superseded row keeps the id and URL it had, which is the whole
 * point of storing history rather than overwriting.
 *
 * @mixin \App\Models\YouTubePublication
 */
class YouTubePublicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'video_id' => $this->youtube_video_id,
            'url' => $this->youtube_url,
            'title' => $this->title,
            'privacy_status' => $this->privacy_status,

            'is_current' => $this->isCurrent(),
            'disposition' => $this->disposition,
            // Whether the link still goes anywhere. The distinction the
            // history exists to record.
            'exists_on_youtube' => $this->survivesOnYouTube(),

            'render_input_hash' => $this->render_input_hash,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'became_current_at' => $this->became_current_at?->toIso8601String(),
            'replaced_at' => $this->replaced_at?->toIso8601String(),
            'remote_deleted_at' => $this->remote_deleted_at?->toIso8601String(),
        ];
    }
}
