<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketingPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Whitelist allowed keys — anything outside this list is a 422.
            'preferences' => ['required', 'array:email,sms,push,product_drops,weekly_digest'],
            'preferences.email' => ['sometimes', 'boolean'],
            'preferences.sms' => ['sometimes', 'boolean'],
            'preferences.push' => ['sometimes', 'boolean'],
            'preferences.product_drops' => ['sometimes', 'boolean'],
            'preferences.weekly_digest' => ['sometimes', 'boolean'],
        ];
    }
}
