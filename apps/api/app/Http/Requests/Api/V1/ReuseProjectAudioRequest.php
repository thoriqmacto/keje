<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reuse a recording already on the server.
 *
 * The browser names a **project**, never a file. That is the point: this is
 * the one feature in the studio that could plausibly have been built as "tell
 * me which file on disk to use", and a path from a request is exactly what
 * this codebase never accepts. The project UUID is looked up scoped to the
 * caller, and the server reads that project's own stored path — so there is no
 * value a request can carry that names a file the caller does not already own.
 */
class ReuseProjectAudioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source_project_id' => [
                'required', 'string',
                // Scoped to the caller: a UUID belonging to somebody else
                // resolves to nothing rather than to their recording, so this
                // cannot be used to probe whether another account's project
                // exists either.
                Rule::exists('content_projects', 'uuid')->where('user_id', $this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'source_project_id.exists' => 'That recording is no longer available.',
        ];
    }
}
