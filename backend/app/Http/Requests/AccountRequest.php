<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    /** Authorize before validating so foreign or read-only writes get 403, not 422. */
    public function authorize(): bool
    {
        $record = $this->route('account');

        return $record ? $this->user()->can('update', $record) : $this->user()->can('create', Account::class);
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'type' => [$req, Rule::enum(AccountType::class)],
            'provider' => ['nullable', 'string', 'max:120'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'balance' => ['sometimes', 'numeric', 'between:-9999999999999,9999999999999'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'last4' => ['nullable', 'digits:4'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }
}
