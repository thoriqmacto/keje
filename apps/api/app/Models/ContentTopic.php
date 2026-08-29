<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lecture series ("Riyadhush Shalihin"), rendered as element #1 of the
 * Kajian Tematik template and conceptually mapped to a YouTube playlist.
 */
class ContentTopic extends Model
{
    use HasFactory;
    use HasUuid;

    /** user_id is assigned via the relation, never mass-assigned. */
    protected $fillable = ['name', 'slug', 'description', 'youtube_playlist_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contentProjects(): HasMany
    {
        return $this->hasMany(ContentProject::class, 'topic_id');
    }

    /**
     * The sequence number a new project in this topic should default to.
     * Suggestion only — the form allows an explicit override.
     */
    public function nextSequence(): int
    {
        return (int) $this->contentProjects()->max('topic_sequence') + 1;
    }
}
