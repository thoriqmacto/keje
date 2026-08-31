<?php

namespace App\Models;

use App\Enums\RenderJobStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single render attempt, kept for history. ffmpeg_log holds a truncated
 * diagnostic tail — never the full FFmpeg output.
 */
class RenderJob extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RenderJobStatus::class,
            'post_actions' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'output_duration' => 'float',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }
}
