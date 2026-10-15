<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes transactions. Every write adjusts account balances
 * inside one DB transaction so balances can never drift from the ledger.
 */
class TransactionService
{
    public function create(User $owner, array $data): Transaction
    {
        return DB::transaction(function () use ($owner, $data) {
            $tx = new Transaction;
            $tx->user_id = $owner->id;
            $this->fill($tx, $data);
            $tx->save();
            $this->apply($tx, 1);

            return $tx;
        });
    }

    public function update(Transaction $tx, array $data): Transaction
    {
        return DB::transaction(function () use ($tx, $data) {
            $this->apply($tx, -1);
            $this->fill($tx, $data);
            $tx->save();
            $this->apply($tx, 1);

            return $tx;
        });
    }

    public function delete(Transaction $tx): void
    {
        DB::transaction(function () use ($tx) {
            $this->apply($tx, -1);
            $tx->delete();
        });
    }

    private function fill(Transaction $tx, array $data): void
    {
        $tx->fill($data);
        $account = Account::findOrFail($tx->account_id);
        $tx->currency = $account->currency;

        if ($tx->type === TransactionType::Transfer) {
            $tx->category_id = null;
            $to = Account::findOrFail($tx->to_account_id);
            if (! array_key_exists('to_amount', $data) || $data['to_amount'] === null) {
                $tx->to_amount = CurrencyConverter::for($account->user)
                    ->convert($tx->amount, $account->currency, $to->currency);
            }
        } else {
            $tx->to_account_id = null;
            $tx->to_amount = null;
        }
    }

    /** Apply (+1) or reverse (-1) a transaction's effect on balances. */
    private function apply(Transaction $tx, int $sign): void
    {
        $ids = array_filter([$tx->account_id, $tx->to_account_id]);
        // Lock in id order to avoid deadlocks between concurrent writes.
        Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        $amount = (float) $tx->amount * $sign;

        match ($tx->type) {
            TransactionType::Expense => Account::whereKey($tx->account_id)->decrement('balance', $amount),
            TransactionType::Income => Account::whereKey($tx->account_id)->increment('balance', $amount),
            TransactionType::Transfer => $this->transfer($tx, $amount, (float) $tx->to_amount * $sign),
        };
    }

    private function transfer(Transaction $tx, float $out, float $in): void
    {
        Account::whereKey($tx->account_id)->decrement('balance', $out);
        Account::whereKey($tx->to_account_id)->increment('balance', $in);
    }
}
