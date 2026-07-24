<?php

namespace App\Http\Requests\Auth;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'type' => ['sometimes', 'string', 'in:email,phone'],
            'name' => ['nullable', 'string', 'max:100'],
            // Which consumer site the OTP is for. Defaults to bids for backward
            // compatibility with existing callers that don't send it.
            'platform' => ['sometimes', 'string', Rule::in([Platform::BIDS->value, Platform::BUY->value])],
        ];
    }
}
