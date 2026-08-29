<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentProjectRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'working_title' => ['required', 'string', 'max:255'],

            // Topic and speaker are referenced by UUID and must belong to the
            // caller — this is the authorization check, not just validation.
            'topic_id' => [
                'nullable', 'string',
                Rule::exists('content_topics', 'uuid')->where('user_id', $userId),
            ],
            'topic_sequence' => ['nullable', 'integer', 'min:1', 'max:9999'],

            'speaker_id' => [
                'nullable', 'string',
                Rule::exists('speakers', 'uuid')->where('user_id', $userId),
            ],

            'template_key' => ['nullable', 'string', Rule::in($this->availableTemplates())],

            'primary_title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'part_number' => ['nullable', 'integer', 'min:1', 'max:999'],

            // Only a known, application-owned toggle — never a raw FFmpeg option.
            'render_settings' => ['nullable', 'array'],
            'render_settings.loudnorm' => ['nullable', 'boolean'],

            'youtube_metadata' => ['nullable', 'array'],
            ...(new YouTubeMetadataRules)->rules('youtube_metadata'),
        ];
    }

    /** @return list<string> */
    private function availableTemplates(): array
    {
        return app(\App\Services\Media\TemplateRegistry::class)->keys();
    }
}
