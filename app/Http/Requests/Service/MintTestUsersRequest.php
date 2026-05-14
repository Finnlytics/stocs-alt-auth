<?php

namespace App\Http\Requests\Service;

use Illuminate\Foundation\Http\FormRequest;

class MintTestUsersRequest extends FormRequest
{
    public const MAX_COUNT = 200;

    public function authorize(): bool
    {
        return ! app()->isProduction();
    }

    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COUNT],
            'prefix' => ['sometimes', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/i'],
        ];
    }
}
