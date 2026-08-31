<?php

namespace App\Models;

use App\Enums\OldVideoDisposition;
use App\Enums\ReplacementStage;
use App\Enums\ReplacementStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-flight correction of a published video.
 *
 * This row is the workflow. A worker that dies between two YouTube calls is
 * resumed from these columns, and the columns are facts about YouTube — a
 * video id exists or it does not, the old video has been disposed of or it has
 * not — rather than a step counter that can disagree with reality.
 *
 * That distinction is the whole design. `nextStage()` reads the facts, so a
 * retry cannot re-run a step that already succeeded no matter what the status
 * says or how many times it is called. Re-running the upload step is the one
 * unrecoverable mistake available here: it publishes a second real copy of the
 * lecture to the channel, which no amount of local state fixing undoes.
 */
class YouTubeReplacement extends Model
{
    use HasFactory;
    use HasUuid;

    /**
     * Named explicitly: Laravel snake-cases the class to
     * `you_tube_replacements`, splitting on the capital T in YouTube.
     */
    protected $table = 'youtube_replacements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReplacementStatus::class,
            'old_disposition' => OldVideoDisposition::class,
            'upload_progress' => 'float',
            'failed_at' => 'datetime',
            'started_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'old_disposed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oldPublication(): BelongsTo
    {
        return $this->belongsTo(YouTubePublication::class, 'old_publication_id');
    }

    public function newPublication(): BelongsTo
    {
        return $this->belongsTo(YouTubePublication::class, 'new_publication_id');
    }

    /** Replacements holding the per-project lock. */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('active_key');
    }

    /**
     * The next step that still has work to do, from what actually happened.
     *
     * Null means every step is done. Deliberately derived rather than stored:
     * a stored "current step" can be written before or after the call it
     * describes, and either choice is wrong for one of the two crash windows.
     * These three facts cannot lie about YouTube's state.
     */
    public function nextStage(): ?ReplacementStage
    {
        if (blank($this->new_video_id)) {
            return ReplacementStage::Upload;
        }

        if ($this->old_disposed_at === null) {
            return ReplacementStage::DisposeOld;
        }

        if ($this->completed_at === null) {
            return ReplacementStage::Finalize;
        }

        return null;
    }

    /**
     * The old video is still the project's public video.
     *
     * Answered from the disposal fact rather than the status, because a failed
     * replacement can sit on either side of that line: a failed upload leaves
     * the old video untouched, a failed finalisation is past its deletion. The
     * status cannot tell those apart, and this is precisely the question a
     * worried user is asking — "is my video still up".
     */
    public function oldStillCurrent(): bool
    {
        return $this->old_disposed_at === null;
    }

    /** The corrected video exists on YouTube — the point of no cheap restart. */
    public function hasReplacementVideo(): bool
    {
        return filled($this->new_video_id);
    }

    /**
     * Cancelling can still put things back the way they were.
     *
     * Once the old video has been deleted there is nothing to restore it to,
     * so past that point "cancel" would only mean abandoning the new video as
     * well — leaving the project with no video at all.
     */
    public function isCancellable(): bool
    {
        return ! $this->status->isTerminal()
            && $this->old_disposed_at === null
            && ! $this->status->isInFlight();
    }

    /** A user-facing sentence for a stalled replacement. */
    public function blockingSummary(): ?string
    {
        if ($this->status !== ReplacementStatus::Failed) {
            return null;
        }

        $stage = $this->nextStage();

        return $stage === null ? null : $this->summaryForStage($stage);
    }

    /**
     * What is safe right now, given where this stopped.
     *
     * The leading half of every failure message. Each sentence leads with the
     * reassurance rather than the error, because the first thing someone needs
     * to know when a replacement breaks is whether their published lecture is
     * still there — not which Google error code came back.
     */
    public function summaryForStage(ReplacementStage $stage): string
    {
        return match ($stage) {
            ReplacementStage::Upload => 'The replacement upload failed. Your existing YouTube video was not deleted and has not changed.',
            ReplacementStage::DisposeOld => 'The replacement is uploaded and private, but the previous video could not be removed. Your published video has not changed.',
            ReplacementStage::Finalize => 'The replacement is live but still private: its playlist, thumbnail or visibility could not be applied.',
        };
    }
}
