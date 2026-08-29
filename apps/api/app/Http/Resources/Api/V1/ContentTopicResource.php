<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ContentTopic
 */
class ContentTopicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'youtube_playlist_id' => $this->youtube_playlist_id,
            'projects_count' => $this->whenCounted('contentProjects'),
            'next_sequence' => $this->when(
                $this->relationLoaded('contentProjects') || $this->exists,
                fn () => $this->nextSequence(),
            ),
            'projects' => ContentProjectSummaryResource::collection(
                $this->whenLoaded('contentProjects'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
