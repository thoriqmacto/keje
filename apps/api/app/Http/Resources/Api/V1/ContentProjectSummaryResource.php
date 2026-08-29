<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for the Content Studio list and topic detail pages.
 *
 * Deliberately excludes filesystem paths — the studio list never needs them
 * and they must not leave the server.
 *
 * @mixin \App\Models\ContentProject
 */
class ContentProjectSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'working_title' => $this->working_title,
            'slug' => $this->slug,
            'template_key' => $this->template_key,

            'topic' => $this->whenLoaded('topic', fn () => [
                'id' => $this->topic->uuid,
                'name' => $this->topic->name,
                'sequence' => $this->topic_sequence,
            ]),
            'topic_sequence' => $this->topic_sequence,

            'speaker' => $this->whenLoaded('speaker', fn () => [
                'id' => $this->speaker->uuid,
                'name' => $this->speaker->name,
            ]),

            'audio_duration' => $this->source_audio_duration,
            'has_audio' => filled($this->source_audio_path),
            'has_background' => filled($this->background_image_path),

            // Three independent pipelines — never collapsed into one status.
            'render' => [
                'status' => $this->render_status->value,
                'label' => $this->render_status->label(),
                // Supplied by the index query's subselect; 0 when never rendered.
                'progress' => (int) ($this->render_progress ?? 0),
            ],
            'drive' => [
                'status' => $this->drive_status->value,
                'label' => $this->drive_status->label(),
            ],
            'youtube' => [
                'status' => $this->youtube_status->value,
                'label' => $this->youtube_status->label(),
                'scheduled_at' => $this->youtube_publish_at?->toIso8601String(),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
