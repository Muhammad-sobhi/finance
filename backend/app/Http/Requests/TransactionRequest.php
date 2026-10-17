<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    /** Authorize before validating so foreign or read-only writes get 403, not 422. */
    public function authorize(): bool
    {
        $record = $this->route('transaction');

        return $record ? $this->user()->can('update', $record) : $this->user()->can('create', Transaction::class);
    }

    /** On update, missing fields fall back to the stored values so PATCH works. */
    protected function prepareForValidation(): void
    {
        $tx = $this->route('transaction');
        if ($tx instanceof Transaction) {
            $current = [
                'type' => $tx->type->value,
                'account_id' => $tx->account_id,
                'to_account_id' => $tx->to_account_id,
                'category_id' => $tx->category_id,
                'amount' => (float) $tx->amount,
                'date' => $tx->date->format('Y-m-d'),
                'note' => $tx->note,
            ];
            // A changed amount or account means a stale to_amount must be recomputed.
            if (! $this->hasAny(['amount', 'account_id', 'to_account_id'])) {
                $current['to_amount'] = $tx->to_amount !== null ? (float) $tx->to_amount : null;
            }
            $current['fee'] = $tx->fee !== null ? (float) $tx->fee : null;
            $this->replace(array_merge($current, $this->all()));
        }
        if (! $this->filled('date')) {
            $this->merge(['date' => now()->toDateString()]);
        }
    }

    public function rules(): array
    {
        $ownerId = $this->user()->ownerId();
        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $ownerId);
        $isTransfer = $this->input('type') === TransactionType::Transfer->value;

        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            'account_id' => ['required', 'integer', $ownedAccount],
            'to_account_id' => [Rule::requiredIf($isTransfer), 'nullable', 'integer', $ownedAccount, 'different:account_id'],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('user_id', $ownerId),
                function (string $attr, mixed $value, Closure $fail) use ($isTransfer) {
                    if ($value === null || $isTransfer) {
                        return;
                    }
                    $category = Category::find($value);
                    if ($category && $category->type->value !== $this->input('type')) {
                        $fail('The category does not match the transaction type.');
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'to_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
            // Set by the app for entries made offline (see TransactionController::store).
            'client_id' => ['nullable', 'uuid'],
        ];
    }
}
