<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The cut list, as numbers only.
 *
 * Nothing here can carry an FFmpeg filter, expression or option: the shape is
 * a fixed list of {type, start, end}, `type` is constrained to a single known
 * value, and the timestamps are numeric. AudioEditService then applies the
 * ordering and overlap rules and builds the graph itself.
 */
class UpdateProjectAudioEditsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'audio_edits' => ['present', 'array', 'max:100'],
            'audio_edits.*.type' => ['nullable', 'string', Rule::in(['cut'])],
            'audio_edits.*.start' => ['required', 'numeric', 'min:0'],
            'audio_edits.*.end' => ['required', 'numeric', 'gt:audio_edits.*.start'],
        ];
    }

    public function messages(): array
    {
        return [
            'audio_edits.max' => 'That is more removed sections than a lecture should need.',
            'audio_edits.*.end.gt' => 'A removed section must end after it starts.',
        ];
    }
}
