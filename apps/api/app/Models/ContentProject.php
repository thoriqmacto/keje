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
