<?php

namespace App\Models;

use App\Enums\DriveStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One produced video.
 *
 * Carries three independent pipeline statuses (render / drive / youtube) so a
 * failed Drive backup never invalidates a good render.
 */
class ContentProject extends Model
{
    use HasFactory;
    use HasUuid;

    /**
     * Only user-editable fields. Media paths, statuses and integration results
     * are set by services, never by request payloads.
     */
    protected $fillable = [
        'topic_id',
        'topic_sequence',
        'speaker_id',
        'working_title',
        'slug',
        'template_key',
        'primary_title',
        'subtitle',
        'part_number',
        'render_settings',
        'youtube_metadata',
    ];

    /**
     * Mirrors the migration defaults on the model itself, so a freshly created
     * instance already has its three pipeline statuses rather than nulls that
     * the enum casts cannot resolve.
     */
    protected $attributes = [
        'render_status' => 'draft',
        'drive_status' => 'pending',
        'youtube_status' => 'pending',
        'template_key' => 'kajian-tematik',
    ];

    protected function casts(): array
    {
        return [
            'media_pruned_at' => 'datetime',
            'audio_edits' => 'array',
            'youtube_remote_publish_at' => 'datetime',
            'youtube_remote_synced_at' => 'datetime',
            'thumbnail_generated_at' => 'datetime',
            'youtube_thumbnail_synced_at' => 'datetime',
            'youtube_playlist_added_at' => 'datetime',
            'render_status' => RenderStatus::class,
            'drive_status' => DriveStatus::class,
            'youtube_status' => YouTubeStatus::class,
            'render_settings' => 'array',
            'youtube_metadata' => 'array',
            'source_audio_duration' => 'float',
            'output_duration' => 'float',
            'rendered_at' => 'datetime',
            'drive_uploaded_at' => 'datetime',
            'youtube_uploaded_at' => 'datetime',
            'youtube_publish_at' => 'datetime',
            'finalized_at' => 'datetime',
            // Derived from the render fingerprint and persisted so the Studio
            // list can filter on it; see ContentProjectObserver.
            'render_is_stale' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(ContentTopic::class, 'topic_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }

    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    public function latestRenderJob(): ?RenderJob
    {
        return $this->renderJobs()->latest('id')->first();
    }

    /** Every video this project has had on YouTube, oldest first. */
    public function youtubePublications(): HasMany
    {
        return $this->hasMany(YouTubePublication::class);
    }

    public function youtubeReplacements(): HasMany
    {
        return $this->hasMany(YouTubeReplacement::class);
    }

    /**
     * The correction currently in flight, if any.
     *
     * Only one can exist — the database enforces it through a unique
     * active_key — so this is a single row rather than a "latest of many".
     */
    public function activeYouTubeReplacement(): ?YouTubeReplacement
    {
        return $this->youtubeReplacements()->whereNotNull('active_key')->first();
    }

    /** The publication representing this project on YouTube right now. */
    public function currentYouTubePublication(): ?YouTubePublication
    {
        if ($this->current_youtube_publication_id === null) {
            return null;
        }

        return YouTubePublication::find($this->current_youtube_publication_id);
    }

    /**
     * The video on YouTube was made from an older render.
     *
     * The precise question behind "do I need to replace this video, or just
     * edit its description". Answered from render fingerprints, never from
     * timestamps: re-saving a project moves updated_at without changing a
     * single frame, and a title typo fixed in the YouTube metadata changes
     * updated_at without changing the video at all.
     *
     * Unknown when the video predates fingerprinting — reporting every
     * historical upload as outdated on the day this ships would be noise.
     */
    public function youtubeVideoIsOutdated(): bool
    {
        if (blank($this->youtube_video_id) || blank($this->youtube_render_input_hash)) {
            return false;
        }

        return $this->youtube_render_input_hash
            !== app(\App\Services\Media\RenderInputFingerprint::class)->for($this);
    }

    /**
     * Attach the latest attempt's progress as `render_progress` via a
     * subselect, so listing N projects stays one query.
     */
    public function scopeWithRenderProgress(Builder $query): Builder
    {
        return $query
            ->select("{$this->getTable()}.*")
            ->addSelect(['render_progress' => RenderJob::query()
                ->select('progress_percent')
                ->whereColumn('content_project_id', "{$this->getTable()}.id")
                ->latest('id')
                ->limit(1),
            ]);
    }

    /**
     * The cut list with its consequences worked out.
     *
     * Kept on the model so the resource, the renderer and the studio all read
     * the same numbers rather than each recomputing them slightly differently.
     *
     * @return array{cuts: list<array<string, mixed>>, source_duration: ?float, removed_duration: float, effective_duration: ?float}
     */
    public function audioEditSummary(): array
    {
        $cuts = array_values((array) ($this->audio_edits ?? []));
        $source = $this->source_audio_duration === null ? null : (float) $this->source_audio_duration;

        if ($source === null) {
            return [
                'cuts' => $cuts,
                'source_duration' => null,
                'removed_duration' => 0.0,
                'effective_duration' => null,
            ];
        }

        $edits = app(\App\Services\Media\AudioEditService::class);

        return [
            'cuts' => $cuts,
            'source_duration' => $source,
            'removed_duration' => $edits->removedDuration($cuts, $source),
            'effective_duration' => $edits->keptDuration($cuts, $source),
        ];
    }

    /** Root of this project's private storage tree, relative to the local disk. */
    public function storageDirectory(): string
    {
        return "content/{$this->uuid}";
    }

    /** Both source files are present, so a render can be attempted. */
    public function hasRequiredMedia(): bool
    {
        return filled($this->source_audio_path) && filled($this->background_image_path);
    }

    /** All template text needed by the renderer has been supplied. */
    public function hasRequiredText(): bool
    {
        return filled($this->primary_title);
    }

    public function isRenderable(): bool
    {
        return $this->hasRequiredMedia() && $this->hasRequiredText();
    }
}
