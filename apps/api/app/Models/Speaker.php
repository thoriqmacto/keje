<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable speaker. The stored name keeps its natural casing —
 * uppercasing is a Kajian Tematik rendering decision, not a data change.
 */
class Speaker extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = ['name', 'display_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contentProjects(): HasMany
    {
        return $this->hasMany(ContentProject::class);
    }

    /** What a template should draw, before any template-specific casing. */
    public function renderName(): string
    {
        return $this->display_name ?: $this->name;
    }
}
