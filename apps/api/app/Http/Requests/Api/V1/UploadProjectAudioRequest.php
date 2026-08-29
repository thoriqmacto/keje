<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * First-pass validation for a lecture recording.
 *
 * Extension and size only — these are cheap and reject obvious junk before it
 * is stored. The authoritative check is ffprobe, which runs in the controller
 * after the file lands and confirms a genuinely decodable audio stream.
 */
class UploadProjectAudioRequest extends FormRequest
{
    public function rules(): array
    {
        $maxKb = (int) config('media.max_audio_mb') * 1024;
        $extensions = implode(',', (array) config('media.audio_extensions'));

        return [
            'audio' => ['required', 'file', "max:{$maxKb}", "extensions:{$extensions}"],
        ];
    }

    public function messages(): array
    {
        return [
            'audio.max' => 'The recording may not be larger than '.config('media.max_audio_mb').' MB.',
            'audio.extensions' => 'Upload one of: '
                .implode(', ', (array) config('media.audio_extensions')).'.',
        ];
    }
}
