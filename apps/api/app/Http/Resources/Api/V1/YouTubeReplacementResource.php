<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ReplacementStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A replacement's state, shaped for the progress display.
 *
 * Reports what exists on YouTube right now rather than an internal step name,
 * because the question people actually have during a replacement is "is my
 * video still up". `old_still_current` answers it directly.
 *
 * @mixin \App\Models\YouTubeReplacement
 */
class YouTubeReplacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'label' => $this->status->label(),

            // The reassurance, computed rather than phrased in the UI: until
            // the old video is disposed of, nothing the viewer can see has
            // changed.
            'old_still_current' => $this->oldStillCurrent(),
            'old_video_id' => $this->old_video_id,
            'new_video_id' => $this->new_video_id,
            'old_disposition' => $this->old_disposition->value,

            'stage' => $this->nextStage()?->value,
            'upload_progress' => $this->upload_progress,

            'is_active' => $this->active_key !== null,
            'is_failed' => $this->status === ReplacementStatus::Failed,
            'is_cancellable' => $this->isCancellable(),
            'error' => $this->error,
            // A sentence about what is safe right now, not just what broke.
            'blocking_summary' => $this->blockingSummary(),

            'started_at' => $this->started_at?->toIso8601String(),
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'old_disposed_at' => $this->old_disposed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
