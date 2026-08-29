<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Media\TemplateRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContentProjectRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'working_title' => ['sometimes', 'required', 'string', 'max:255'],

            'topic_id' => [
                'nullable', 'string',
                Rule::exists('content_topics', 'uuid')->where('user_id', $userId),
            ],
            'topic_sequence' => ['nullable', 'integer', 'min:1', 'max:9999'],

            'speaker_id' => [
                'nullable', 'string',
                Rule::exists('speakers', 'uuid')->where('user_id', $userId),
            ],

            'template_key' => ['sometimes', 'required', 'string', Rule::in(app(TemplateRegistry::class)->keys())],

            'primary_title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'part_number' => ['nullable', 'integer', 'min:1', 'max:999'],

            'render_settings' => ['nullable', 'array'],
            'render_settings.loudnorm' => ['nullable', 'boolean'],

            'youtube_metadata' => ['nullable', 'array'],
            ...(new YouTubeMetadataRules)->rules('youtube_metadata'),
        ];
    }
}
