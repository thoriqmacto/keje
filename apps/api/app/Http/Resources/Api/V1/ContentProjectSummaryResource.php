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
                // The output was produced from inputs that have since
                // changed, so it no longer represents this project. Not an
                // error and not a reason to delete anything — the file is
                // still a real render of an earlier revision.
                //
                // Persisted rather than recomputed here: a hash comparison per
                // row is cheap, but it cannot be asked for in SQL, and
                // "which of my videos need re-rendering" is exactly the
                // question this list should be able to answer.
                'stale' => (bool) $this->render_is_stale,
            ],
            'drive' => [
                'status' => $this->drive_status->value,
                'label' => $this->drive_status->label(),
            ],
            'youtube' => [
                'status' => $this->youtube_status->value,
                'label' => $this->youtube_status->label(),
                'scheduled_at' => $this->youtube_publish_at?->toIso8601String(),

                /*
                 * The schedule this project is *going* to ask for, for the
                 * long stretch where it is queued and YouTube has never heard
                 * of it. Without this the list says only "Pending" for a
                 * project whose publication date was decided days ago, which
                 * is the one fact somebody scanning the column wants.
                 *
                 * Withheld once a video exists, and that is the point of
                 * asking the enum rather than comparing timestamps: the
                 * metadata keeps its publish_at after the upload, so a live
                 * video would otherwise go on advertising a publication that
                 * has already happened — or worse, one that never will.
                 */
                'planned_publish_at' => $this->youtube_status->hasVideo()
                    ? null
                    : $this->plannedPublishAt()?->toIso8601String(),

                // The list shows what YouTube says now, so a video that has
                // since published stops claiming to be scheduled.
                'remote_status' => $this->youtube_remote_status,
                'remote_label' => $this->youtube_remote_status === null
                    ? null
                    : \App\Enums\YouTubeRemoteStatus::from($this->youtube_remote_status)->label(),
                'remote_synced_at' => $this->youtube_remote_synced_at?->toIso8601String(),

                /*
                 * A correction in flight, so the list can say "Replacing…"
                 * rather than something alarming and untrue. The distinction
                 * that matters is `replacement_failed` with the video still
                 * published: the workflow broke, but the lecture is up and
                 * unchanged, and showing that as "Failed" would send someone
                 * to check a video that is perfectly fine.
                 *
                 * Read from a subquery the list adds, not from a relation.
                 * Asking each project for its active replacement is one query
                 * per row — twenty-five extra statements on a default page.
                 */
                'is_replacing' => $this->active_replacement_status !== null,
                'replacement_failed' => $this->active_replacement_status
                    === \App\Enums\ReplacementStatus::Failed->value,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
