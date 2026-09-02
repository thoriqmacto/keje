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

            // Gated on the descriptive column, not the path: a pruned
            // project keeps its metadata and only loses its bytes.
            'source_audio' => filled($this->source_audio_original_name) ? [
                'stored' => filled($this->source_audio_path),
                'original_name' => $this->source_audio_original_name,
                'mime' => $this->source_audio_mime,
                'size' => $this->source_audio_size,
                'duration' => $this->source_audio_duration,
                'codec' => $this->source_audio_codec,
                'sample_rate' => $this->source_audio_sample_rate,
                'channels' => $this->source_audio_channels,
                'bitrate' => $this->source_audio_bitrate,
            ] : null,

            'background_image' => filled($this->background_image_original_name) ? [
                'stored' => filled($this->background_image_path),
                'original_name' => $this->background_image_original_name,
                'mime' => $this->background_image_mime,
                'size' => $this->background_image_size,
                'width' => $this->background_image_width,
                'height' => $this->background_image_height,
            ] : null,

            // Removed sections, plus the arithmetic the studio would
            // otherwise have to repeat: the effective length is what the
            // render will actually be.
            'audio_edits' => $this->audioEditSummary(),

            'is_renderable' => $this->isRenderable(),

            'render' => [
                'status' => $this->render_status->value,
                'label' => $this->render_status->label(),
                'progress' => $latest?->progress_percent ?? 0,
                'error' => $this->render_error,
                // The output was produced from inputs that have since
                // changed, so it no longer represents this project. Not an
                // error and not a reason to delete anything — the file is
                // still a real render of an earlier revision.
                'stale' => app(\App\Services\Media\RenderInputFingerprint::class)->isStale($this->resource),
                'rendered_at' => $this->rendered_at?->toIso8601String(),
                'output_size' => $this->output_size,
                'output_duration' => $this->output_duration,
                'has_output' => filled($this->output_path),
                // Local media removed after the Drive backup; the project
                // now refers to its Drive copy.
                'media_pruned_at' => $this->media_pruned_at?->toIso8601String(),
                'attempts' => $this->renderJobs()->count(),
            ],

            'youtube_playlist' => [
                'id' => $this->youtube_playlist_id,
                'item_id' => $this->youtube_playlist_item_id,
                'added_at' => $this->youtube_playlist_added_at?->toIso8601String(),
                // A failed assignment leaves the video uploaded; this is what
                // makes that visible and retryable.
                'error' => $this->youtube_playlist_error,
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
                // The schedule not yet sent to YouTube — see the summary
                // resource for why it stops once a video exists. Derived here
                // too rather than left to the client to dig out of `metadata`,
                // so both screens answer "when does this go live" the same way.
                'planned_publish_at' => $this->youtube_status->hasVideo()
                    ? null
                    : $this->plannedPublishAt()?->toIso8601String(),
                'error' => $this->youtube_error,
                'metadata' => $this->youtube_metadata,
                // What Google says now, kept apart from our own pipeline status:
                // "our upload failed" and "the video is private" are different
                // facts and must not collapse into one value.
                // Which render this video was made from, and whether that is
                // still the current one. The distinction between "edit the
                // description" and "replace the video" rests entirely here.
                'render_input_hash' => $this->youtube_render_input_hash,
                'video_is_outdated' => $this->youtubeVideoIsOutdated(),

                'remote' => [
                    'status' => $this->youtube_remote_status,
                    'label' => $this->youtube_remote_status === null
                        ? null
                        : \App\Enums\YouTubeRemoteStatus::from($this->youtube_remote_status)->label(),
                    'privacy_status' => $this->youtube_remote_privacy_status,
                    'publish_at' => $this->youtube_remote_publish_at?->toIso8601String(),
                    'synced_at' => $this->youtube_remote_synced_at?->toIso8601String(),
                    'sync_error' => $this->youtube_remote_sync_error,
                ],
            ],

            // Thumbnail state is its own block: a failed thumbnail on a
            // video that uploaded fine is not a failed upload.
            'thumbnail' => [
                'timestamp' => $this->thumbnail_timestamp,
                'selected' => filled($this->thumbnail_path),
                'generated_at' => $this->thumbnail_generated_at?->toIso8601String(),
                'youtube_status' => $this->youtube_thumbnail_status,
                'youtube_error' => $this->youtube_thumbnail_error,
                'youtube_synced_at' => $this->youtube_thumbnail_synced_at?->toIso8601String(),
            ],

            // Whether a correction is possible, and if not, the sentence
            // saying why. Sent with the project so the UI never offers a
            // button the API would refuse.
            'replacement' => $this->replacementBlock(),

            // The working files are kept for a while after publishing so a
            // mistake can still be corrected; this is what says so, and how
            // the user releases them early.
            'retention' => [
                'finalized_at' => $this->finalized_at?->toIso8601String(),
                'within_correction_window' => app(\App\Services\Media\MediaRetention::class)
                    ->withinCorrectionWindow($this->resource),
            ],

            'render_settings' => $this->render_settings,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Correction state: what is running, and what may be started.
     *
     * Eligibility travels with the project rather than sitting behind its own
     * endpoint so the studio cannot draw an enabled "Replace video" button for
     * a project the API would refuse — the two would drift the first time a
     * precondition changed.
     *
     * @return array<string, mixed>
     */
    private function replacementBlock(): array
    {
        $active = $this->activeYouTubeReplacement();
        $eligibility = app(\App\Services\Google\YouTubeReplacementPolicy::class)
            ->evaluate($this->resource);

        return [
            'active' => $active === null ? null : (new YouTubeReplacementResource($active))->resolve(),
            'can_replace' => $active === null && $eligibility['allowed'],
            'blocked_reason' => $eligibility['reason'],
            'blocked_message' => $eligibility['message'],
            'needs_render' => $eligibility['needs_render'],
            'needs_reconnect' => $eligibility['needs_reconnect'],
            'needs_media' => $eligibility['needs_media'],
        ];
    }
}
