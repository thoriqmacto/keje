<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full project detail.
 *
 * Reports media by *fact* (name, size, duration, codec) and never by path:
 * `source_audio_path` and friends stay server-side. The rendered MP4 is
 * reachable only through the authenticated /video and /download endpoints.
 *
 * @mixin \App\Models\ContentProject
 */
class ContentProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latest = $this->latestRenderJob();

        return [
            'id' => $this->uuid,
            'working_title' => $this->working_title,
            'slug' => $this->slug,
            'template_key' => $this->template_key,

            'topic' => $this->topic === null ? null : [
                'id' => $this->topic->uuid,
                'name' => $this->topic->name,
                'sequence' => $this->topic_sequence,
                'youtube_playlist_id' => $this->topic->youtube_playlist_id,
            ],
            'topic_sequence' => $this->topic_sequence,

            'speaker' => $this->speaker === null ? null : [
                'id' => $this->speaker->uuid,
                'name' => $this->speaker->name,
                'render_name' => $this->speaker->renderName(),
            ],

            // Template text, stored in natural case.
            'primary_title' => $this->primary_title,
            'subtitle' => $this->subtitle,
            'part_number' => $this->part_number,

            'source_audio' => filled($this->source_audio_path) ? [
                'original_name' => $this->source_audio_original_name,
                'mime' => $this->source_audio_mime,
                'size' => $this->source_audio_size,
                'duration' => $this->source_audio_duration,
                'codec' => $this->source_audio_codec,
                'sample_rate' => $this->source_audio_sample_rate,
                'channels' => $this->source_audio_channels,
                'bitrate' => $this->source_audio_bitrate,
            ] : null,

            'background_image' => filled($this->background_image_path) ? [
                'original_name' => $this->background_image_original_name,
                'mime' => $this->background_image_mime,
                'size' => $this->background_image_size,
                'width' => $this->background_image_width,
                'height' => $this->background_image_height,
            ] : null,

            'is_renderable' => $this->isRenderable(),

            'render' => [
                'status' => $this->render_status->value,
                'label' => $this->render_status->label(),
                'progress' => $latest?->progress_percent ?? 0,
                'error' => $this->render_error,
                'rendered_at' => $this->rendered_at?->toIso8601String(),
                'output_size' => $this->output_size,
                'output_duration' => $this->output_duration,
                'has_output' => filled($this->output_path),
                'attempts' => $this->renderJobs()->count(),
            ],

            'drive' => [
                'status' => $this->drive_status->value,
                'label' => $this->drive_status->label(),
                'file_id' => $this->drive_file_id,
                'file_name' => $this->drive_file_name,
                'web_view_link' => $this->drive_web_view_link,
                'uploaded_at' => $this->drive_uploaded_at?->toIso8601String(),
                'error' => $this->drive_error,
            ],

            'youtube' => [
                'status' => $this->youtube_status->value,
                'label' => $this->youtube_status->label(),
                'video_id' => $this->youtube_video_id,
                'url' => $this->youtube_url,
                'uploaded_at' => $this->youtube_uploaded_at?->toIso8601String(),
                'publish_at' => $this->youtube_publish_at?->toIso8601String(),
                'error' => $this->youtube_error,
                'metadata' => $this->youtube_metadata,
            ],

            'render_settings' => $this->render_settings,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
