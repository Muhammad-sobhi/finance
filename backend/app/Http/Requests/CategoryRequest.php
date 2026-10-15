<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    /** Authorize before validating so foreign or read-only writes get 403, not 422. */
    public function authorize(): bool
    {
        $record = $this->route('category');

        return $record ? $this->user()->can('update', $record) : $this->user()->can('create', Category::class);
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:60'],
            'type' => [$req, Rule::enum(CategoryType::class)],
            'icon' => ['sometimes', 'string', 'max:24', 'alpha_dash'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
