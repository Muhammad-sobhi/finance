<?php

namespace App\Exceptions;

use App\Models\Account;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A payment would take an account below what it holds (or past a credit card's limit).
 * Answers 422 like a validation error on `account_id`, plus the numbers the app needs to
 * offer "top up this account" or "choose another account".
 */
class InsufficientFunds extends RuntimeException
{
    public function __construct(public readonly Account $account, public readonly float $available, public readonly float $shortfall)
    {
        $fmt = fn (float $n) => number_format($n, $n == floor($n) ? 0 : 2).' '.$account->currency;
        parent::__construct(__('funds.short', ['account' => $account->name, 'available' => $fmt($available), 'shortfall' => $fmt($shortfall)],
            auth()->user()?->locale ?? $account->user?->locale));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'insufficient_funds',
            'errors' => ['account_id' => [$this->getMessage()]],
            'funds' => [
                'account_id' => $this->account->id,
                'account' => $this->account->name,
                'currency' => $this->account->currency,
                'available' => $this->available,
                'shortfall' => $this->shortfall,
            ],
        ], 422);
    }
}
