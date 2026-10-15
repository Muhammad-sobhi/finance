<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'provider' => $this->provider,
            'currency' => $this->currency,
            'balance' => (float) $this->balance,
            'base_balance' => $this->when(isset($this->base_balance), fn () => $this->base_balance),
            'credit_limit' => $this->credit_limit !== null ? (float) $this->credit_limit : null,
            'due_day' => $this->due_day,
            'last4' => $this->last4,
            'account_number' => $this->account_number,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'sort' => $this->sort,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
