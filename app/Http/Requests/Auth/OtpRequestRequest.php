<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class OtpRequestRequest extends FormRequest
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
            'type' => ['sometimes', 'string', 'in:email,phone'],
        ];
    }
}
