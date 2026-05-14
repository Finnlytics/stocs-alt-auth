<?php

namespace App\Http\Requests\Admin;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'platform' => ['sometimes', Rule::in(array_column(Platform::cases(), 'value'))],
        ];
    }
}
