<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Speaker
 */
class SpeakerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            // Natural casing, as stored. Uppercasing is a render-time concern.
            'name' => $this->name,
            'display_name' => $this->display_name,
            'render_name' => $this->renderName(),
            'projects_count' => $this->whenCounted('contentProjects'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
