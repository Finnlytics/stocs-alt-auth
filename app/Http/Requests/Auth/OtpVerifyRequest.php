<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
