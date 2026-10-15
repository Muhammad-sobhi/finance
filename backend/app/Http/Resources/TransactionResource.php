<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'account_id' => $this->account_id,
            'to_account_id' => $this->to_account_id,
            'category_id' => $this->category_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'to_amount' => $this->to_amount !== null ? (float) $this->to_amount : null,
            'base_amount' => $this->when(isset($this->base_amount), fn () => $this->base_amount),
            'date' => $this->date->format('Y-m-d'),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
