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
            // Exactly four different pages for the phone's bottom bar; null resets to the default.
            'phone_nav' => ['sometimes', 'nullable', 'array', 'size:4'],
            'phone_nav.*' => ['string', 'distinct', Rule::in(User::PHONE_NAV)],
        ];
    }
}
