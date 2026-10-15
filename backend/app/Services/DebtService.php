<?php

namespace App\Services;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Debts can move real money: lending/borrowing (optional account on the debt) and each
 * repayment (optional account on the payment) become transactions linked by `source`.
 */
class DebtService
{
    public function __construct(private readonly TransactionService $tx) {}

    public function create(User $owner, array $data): Debt
    {
        return DB::transaction(function () use ($owner, $data) {
            $debt = $owner->debts()->create($data);
            $this->syncOpening($debt);

            return $debt;
        });
    }

    public function update(Debt $debt, array $data): Debt
    {
        return DB::transaction(function () use ($debt, $data) {
            $debt->update($data);
            $this->syncOpening($debt);
            $this->refreshStatus($debt, array_key_exists('status', $data));

            return $debt;
        });
    }

    public function delete(Debt $debt): void
    {
        DB::transaction(function () use ($debt) {
            foreach ($debt->payments as $payment) {
                $this->deletePayment($payment, refresh: false);
            }
            $debt->transactions->each(fn (Transaction $t) => $this->tx->delete($t));
            $debt->delete();
        });
    }

    public function addPayment(Debt $debt, array $data): DebtPayment
    {
        return DB::transaction(function () use ($debt, $data) {
            $payment = $debt->payments()->create($data);
            if ($payment->account_id) {
                // Repayment flows opposite to the loan: owed to me → money comes in.
                $type = $debt->direction === DebtDirection::OwedToMe ? 'income' : 'expense';
                $this->tx->create($debt->user, $this->txData($debt, $payment->account_id, $type, (float) $payment->amount, $payment->date->format('Y-m-d'), $payment->note) + [
                    'source_type' => $payment->getMorphClass(), 'source_id' => $payment->id,
                ]);
            }
            $this->refreshStatus($debt);

            return $payment;
        });
    }

    public function deletePayment(DebtPayment $payment, bool $refresh = true): void
    {
        DB::transaction(function () use ($payment, $refresh) {
            if ($payment->transaction) {
                $this->tx->delete($payment->transaction);
            }
            $debt = $payment->debt;
            $payment->delete();
            if ($refresh) {
                $this->refreshStatus($debt);
            }
        });
    }

    /** Keep the loan's own transaction in line with the debt's account/amount/date. */
    private function syncOpening(Debt $debt): void
    {
        $existing = $debt->transactions()->first();
        if (! $debt->account_id) {
            if ($existing) {
                $this->tx->delete($existing);
            }

            return;
        }
        // Lending to someone moves money out; borrowing moves it in.
        $type = $debt->direction === DebtDirection::OwedToMe ? 'expense' : 'income';
        $data = $this->txData($debt, $debt->account_id, $type, (float) $debt->amount, $debt->date->format('Y-m-d'), null);

        $existing
            ? $this->tx->update($existing, $data)
            : $this->tx->create($debt->user, $data + ['source_type' => $debt->getMorphClass(), 'source_id' => $debt->id]);
    }

    private function txData(Debt $debt, int $accountId, string $type, float $amount, string $date, ?string $note): array
    {
        $account = Account::findOrFail($accountId);

        return [
            'type' => $type,
            'account_id' => $accountId,
            'category_id' => null,
            'amount' => CurrencyConverter::for($debt->user)->convert($amount, $debt->currency, $account->currency),
            'date' => $date,
            'note' => $note ?: $debt->person,
        ];
    }

    /** Fully paid → settled. A manual status change is respected unless payments say otherwise. */
    private function refreshStatus(Debt $debt, bool $manual = false): void
    {
        $debt->unsetRelation('payments');
        $remaining = $debt->remaining();
        if ($remaining <= 0) {
            $debt->status = DebtStatus::Settled;
        } elseif (! $manual) {
            $debt->status = DebtStatus::Open;
        }
        $debt->save();
    }
}
