<?php

namespace App\Http\Requests\Auth;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // 'phone' identifiers must pass through untouched — only the email
        // form gets lowercased/trimmed here.
        if ($this->has('identifier') && $this->input('type', 'email') === 'email') {
            $this->merge(['identifier' => Str::lower(trim((string) $this->input('identifier')))]);
        }
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
