<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw rows only — balances are NOT adjusted. Use TransactionService when balances matter.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => fn (array $attrs) => Account::find($attrs['account_id'])->user_id,
            'account_id' => Account::factory(),
            'type' => TransactionType::Expense,
            'amount' => 50,
            'currency' => 'EGP',
            'date' => now()->toDateString(),
        ];
    }
}
