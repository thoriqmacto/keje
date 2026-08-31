<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One video Keje has put on YouTube for a project.
 *
 * Append-only. Replacing a video does not overwrite this row — it writes a new
 * one and marks this one replaced, because the old video's id and URL are the
 * only record that the link someone shared last week ever existed. A project
 * that has been corrected twice has three rows and one of them is current.
 *
 * The snapshots (title, privacy, publish time) are taken at publication and
 * never refreshed for a superseded row: once the video is deleted from YouTube
 * nothing can answer for it, and "what was this published as" is a question
 * history is supposed to survive to answer.
 */
class YouTubePublication extends Model
{
    use HasFactory;
    use HasUuid;

    /**
     * Named explicitly: Laravel snake-cases the class to
     * `you_tube_publications`, splitting on the capital T in YouTube.
     */
    protected $table = 'youtube_publications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'became_current_at' => 'datetime',
            'replaced_at' => 'datetime',
            'remote_deleted_at' => 'datetime',
            'remote_synced_at' => 'datetime',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function renderJob(): BelongsTo
    {
        return $this->belongsTo(RenderJob::class);
    }

    /** The publication this one superseded, if any. */
    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_id');
    }

    /** Currently representing its project: became current and never replaced. */
    public function isCurrent(): bool
    {
        return $this->became_current_at !== null && $this->replaced_at === null;
    }

    /** @param  Builder<self>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNotNull('became_current_at')->whereNull('replaced_at');
    }

    /**
     * Keje itself removed this video from YouTube.
     *
     * The distinction matters to the status sync: videos.list returning
     * nothing for a video we deleted on purpose is the expected outcome, not
     * the "deleted from under us" case that deserves a warning.
     */
    public function wasDeletedByKeje(): bool
    {
        return $this->disposition === 'deleted' && $this->remote_deleted_at !== null;
    }

    /** Still on YouTube, just not the project's current video any more. */
    public function survivesOnYouTube(): bool
    {
        return $this->remote_deleted_at === null;
    }
}
