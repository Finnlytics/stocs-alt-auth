<?php

namespace App\Http\Requests\Service;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The `service-key` middleware authenticates the calling backend; there
        // is no user on a service-to-service request.
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['sometimes', Rule::in(array_column(Platform::cases(), 'value'))],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
