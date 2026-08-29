<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Metadata supplied at the moment of upload. Optional — a project that already
 * has stored metadata can be uploaded with an empty body.
 */
class UploadToYouTubeRequest extends FormRequest
{
    public function rules(): array
    {
        return (new YouTubeMetadataRules)->rules();
    }
}
