<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The clean background artwork. `image` verifies it really decodes as an
 * image; ffprobe then confirms it and records the dimensions.
 */
class UploadProjectBackgroundRequest extends FormRequest
{
    public function rules(): array
    {
        $maxKb = (int) config('media.max_image_mb') * 1024;
        $extensions = implode(',', (array) config('media.image_extensions'));

        return [
            'background' => ['required', 'file', 'image', "max:{$maxKb}", "extensions:{$extensions}"],
        ];
    }

    public function messages(): array
    {
        return [
            'background.max' => 'The background may not be larger than '.config('media.max_image_mb').' MB.',
            'background.uploaded' => UploadLimits::message('background', (int) config('media.max_image_mb')),
            'background.extensions' => 'Upload a JPG, PNG or WebP image.',
        ];
    }
}
