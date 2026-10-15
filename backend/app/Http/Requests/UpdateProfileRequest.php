<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
            'theme' => ['sometimes', Rule::in(User::THEMES)],
            'list_mode' => ['sometimes', Rule::in(['table', 'cards'])],
        ];
    }
}
