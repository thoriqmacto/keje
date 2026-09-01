<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DriveStatus;
use App\Enums\RenderStatus;
use App\Enums\YouTubeStatus;
use App\Services\Studio\ProjectListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the Studio list's query string.
 *
 * Deliberately permissive about *values* and strict about *keys*. A topic UUID
 * that does not exist is not an error — it filters to nothing, which is both
 * the safe answer and the honest one, and rejecting it would let someone probe
 * for the existence of another account's topics by watching for a 422.
 *
 * Sort keys are the exception, because they are the one input that could reach
 * SQL as an identifier. They must name a known column.
 */
class IndexContentProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(ProjectListQuery::PAGE_SIZES)],

            // Long enough for a real title, short enough that nobody is
            // sending a payload through the search box.
            'q' => ['nullable', 'string', 'max:200'],

            'sort' => ['nullable', 'string', Rule::in(ProjectListQuery::sortKeys())],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],

            'topic' => ['nullable', 'string', 'max:64'],
            // 'none' is a filter in its own right: projects with no speaker.
            'speaker' => ['nullable', 'string', 'max:64'],

            // The Working title column's own filter, distinct from `q`.
            'working_title' => ['nullable', 'string', 'max:200'],
            // A relative window rather than dates: "last 7 days" is what
            // somebody actually means, and it needs no calendar widget.
            'updated_within' => ['nullable', Rule::in(['today', '7d', '30d'])],

            'render_status' => ['nullable', Rule::in([
                ...array_column(RenderStatus::cases(), 'value'),
                // Derived, and persisted so it can be asked for in SQL.
                'outdated',
            ])],
            'drive_status' => ['nullable', Rule::in(array_column(DriveStatus::cases(), 'value'))],
            'youtube_status' => ['nullable', Rule::in([
                ...array_column(YouTubeStatus::cases(), 'value'),
                // What Google says now, which is not the same question as
                // what our pipeline did.
                'private', 'unlisted', 'processing', 'rejected', 'unavailable',
                // A correction in flight.
                'replacing', 'replacement_failed',
            ])],
        ];
    }

    /**
     * An unusable sort key falls back rather than failing the request.
     *
     * A stale bookmark naming a column that has since been renamed should
     * still show the user their projects. The allow-list above is what keeps
     * this safe; this only decides what happens to input it rejects.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('sort') && ! in_array($this->input('sort'), ProjectListQuery::sortKeys(), true)) {
            $this->merge(['sort' => null, 'direction' => null]);
        }

        if ($this->filled('per_page') && ! in_array((int) $this->input('per_page'), ProjectListQuery::PAGE_SIZES, true)) {
            $this->merge(['per_page' => ProjectListQuery::DEFAULT_PER_PAGE]);
        }
    }
}
