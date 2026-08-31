<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OldVideoDisposition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What to do with the video being replaced.
 *
 * The only thing the browser gets to decide about a replacement. Everything
 * else — which video, which render, which channel — comes from the project's
 * own persisted state, because a request that could name the video to delete
 * would be a request that could delete any video on the channel.
 */
class StartYouTubeReplacementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'old_disposition' => [
                'nullable',
                Rule::in(array_column(OldVideoDisposition::cases(), 'value')),
            ],
        ];
    }
}
