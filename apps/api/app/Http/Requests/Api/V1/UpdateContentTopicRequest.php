<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContentTopicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'youtube_playlist_id' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_topics', 'slug')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('topic')?->id),
            ],
        ];
    }
}
