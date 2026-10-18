<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Exceptions\InsufficientFunds;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes transactions. Every write adjusts account balances
 * inside one DB transaction so balances can never drift from the ledger.
 *
 * Creating or editing may not leave an account short: below zero, or past a credit card's
 * limit (InsufficientFunds rolls the whole write back). Deleting is always allowed.
 */
class TransactionService
{
    private bool $checkFunds = true;

    /** Run without the funds check (importing a statement: history that already happened). */
    public function withoutFundsCheck(callable $fn): mixed
    {
        $previous = $this->checkFunds;
        $this->checkFunds = false;
        try {
            return $fn();
        } finally {
            $this->checkFunds = $previous;
        }
    }

    public function create(User $owner, array $data): Transaction
    {
        return DB::transaction(function () use ($owner, $data) {
            $tx = new Transaction;
            $tx->user_id = $owner->id;
            $tx->client_id = $data['client_id'] ?? null;
            $this->fill($tx, $data);
            $before = $this->available([$tx->account_id, $tx->to_account_id]);
            $tx->save();
            $this->apply($tx, 1);
            $this->syncFee($tx);
            $this->ensureFunds($before);

            return $tx;
        });
    }

    public function update(Transaction $tx, array $data): Transaction
    {
        return DB::transaction(function () use ($tx, $data) {
            // Measured before the old entry is reversed, so only extra money this edit takes counts.
            $before = $this->available([$tx->account_id, $tx->to_account_id, $data['account_id'] ?? null, $data['to_account_id'] ?? null]);
            $this->apply($tx, -1);
            $this->fill($tx, $data);
            $tx->save();
            $this->apply($tx, 1);
            $this->syncFee($tx);
            $this->ensureFunds($before);

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

    /**
     * What each account can still pay: its balance, plus the limit on a credit card.
     * A credit card without a limit is left out (nothing to check against).
     *
     * @param  array<int|null>  $ids
     * @return array<int, float> by account id
     */
    private function available(array $ids): array
    {
        if (! $this->checkFunds) {
            return [];
        }

        return Account::whereIn('id', array_unique(array_filter($ids)))->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Account $a) => $a->type !== AccountType::CreditCard || $a->credit_limit !== null)
            ->mapWithKeys(fn (Account $a) => [$a->id => $this->spendable($a)])
            ->all();
    }

    private function spendable(Account $account): float
    {
        return round((float) $account->balance + ($account->type === AccountType::CreditCard ? (float) $account->credit_limit : 0), 2);
    }

    /**
     * Refuse a write that leaves an account short and shorter than it was. An account that was
     * already short (e.g. a balance typed in negative) can still take entries that don't deepen it.
     *
     * @param  array<int, float>  $before  from available()
     */
    private function ensureFunds(array $before): void
    {
        foreach ($before as $id => $was) {
            $account = Account::findOrFail($id);
            $now = $this->spendable($account);
            if ($now < 0 && $now < $was - 0.005) {
                throw new InsufficientFunds($account, max(0.0, $was), round(-$now, 2));
            }
        }
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
