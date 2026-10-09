<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RenameDriveBackupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * Drive itself accepts almost anything in a filename, including
             * newlines and a leading dot. This is stricter on purpose: the
             * name comes back out in a file listing, an email and a download
             * header, and a name carrying a newline is a name that breaks one
             * of those. 255 is the practical filesystem ceiling anybody
             * downloading it will hit.
             */
            'name' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[^\\x00-\\x1F\\/\\\\]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name cannot contain slashes or control characters.',
        ];
    }
}
