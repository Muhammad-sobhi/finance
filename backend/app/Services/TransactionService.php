<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
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
            $this->syncFee($tx);

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
            $this->syncFee($tx);

            return $tx;
        });
    }

    public function delete(Transaction $tx): void
    {
        DB::transaction(function () use ($tx) {
            $this->dropFee($tx);
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
            $tx->fee = null;
        }
        if ($tx->fee !== null && (float) $tx->fee <= 0) {
            $tx->fee = null;
        }
    }

    /** A transfer's fee is its own expense row (so reports count it), kept in step with the transfer. */
    private function syncFee(Transaction $tx): void
    {
        $this->dropFee($tx);
        if ($tx->type !== TransactionType::Transfer || ! $tx->fee) {
            return;
        }
        $fee = new Transaction;
        $fee->user_id = $tx->user_id;
        $fee->fill([
            'type' => TransactionType::Expense, 'account_id' => $tx->account_id, 'amount' => $tx->fee,
            'category_id' => DefaultCategories::ensure(User::findOrFail($tx->user_id), 'fees'),
            'date' => $tx->date, 'note' => $tx->note, 'source_type' => 'transfer_fee', 'source_id' => $tx->id,
        ]);
        $fee->currency = $tx->currency;
        $fee->save();
        $this->apply($fee, 1);
    }

    private function dropFee(Transaction $tx): void
    {
        Transaction::where('source_type', 'transfer_fee')->where('source_id', $tx->id)->get()
            ->each(function (Transaction $fee) {
                $this->apply($fee, -1);
                $fee->delete();
            });
    }

    /** Apply (+1) or reverse (-1) a transaction's effect on balances. */
    private function apply(Transaction $tx, int $sign): void
    {
        $ids = array_filter([$tx->account_id, $tx->to_account_id]);
        // Lock in id order to avoid deadlocks between concurrent writes. Balances are
        // encrypted, so they're read, adjusted and written back under that lock.
        $accounts = Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $amount = (float) $tx->amount * $sign;

        match ($tx->type) {
            TransactionType::Expense => $this->adjust($accounts[$tx->account_id], -$amount),
            TransactionType::Income => $this->adjust($accounts[$tx->account_id], $amount),
            TransactionType::Transfer => $this->transfer($accounts, $tx, $amount, (float) $tx->to_amount * $sign),
        };
    }

    private function transfer(Collection $accounts, Transaction $tx, float $out, float $in): void
    {
        $this->adjust($accounts[$tx->account_id], -$out);
        $this->adjust($accounts[$tx->to_account_id], $in);
    }

    private function adjust(Account $account, float $delta): void
    {
        $account->balance = round((float) $account->balance + $delta, 2);
        $account->save();
    }
}
