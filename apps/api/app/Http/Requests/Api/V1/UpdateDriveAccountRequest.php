<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename a Drive account, or move it in the fill order.
 *
 * Both are presentation and policy rather than credentials: nothing here can
 * change which Google account a connection is for. That is decided by whoever
 * consented, and re-deciding it is a new consent, not an edit.
 */
class UpdateDriveAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Nullable clears the label and falls back to the email, which is
            // always unambiguous even if it is less friendly.
            'label' => ['sometimes', 'nullable', 'string', 'max:60'],

            /*
             * Lower fills first. Capped well below the column's range because
             * a number nobody can see is still a number somebody will type,
             * and an account at priority 60000 is indistinguishable from a
             * mistake.
             */
            'priority' => ['sometimes', 'integer', 'min:1', 'max:999'],
        ];
    }
}
