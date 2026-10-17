<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Gameya;
use App\Models\GameyaPayment;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Installment plans and gameyas. Each payment can move money on an account:
 * an installment is an expense (cash basis); gameya money is savings in and out,
 * so it is kept out of income/spending totals like debts.
 */
class InstallmentService
{
    public function __construct(private readonly TransactionService $tx) {}

    /** @param array{account_id?: int|null, amount?: float|null, date?: string|null} $data */
    public function payInstallment(InstallmentPlan $plan, array $data): InstallmentPayment
    {
        return DB::transaction(function () use ($plan, $data) {
            InstallmentPlan::whereKey($plan->id)->lockForUpdate()->first();
            $paid = $plan->payments()->count();
            if ($paid >= $plan->count) {
                throw ValidationException::withMessages(['amount' => 'All installments are paid.']);
            }
            $payment = $plan->payments()->create([
                'number' => $paid + 1,
                'amount' => $data['amount'] ?? $plan->installment_amount,
                'date' => $data['date'] ?? CarbonImmutable::today()->toDateString(),
                'account_id' => array_key_exists('account_id', $data) ? $data['account_id'] : $plan->account_id,
            ]);
            $this->record($payment, $plan->user, 'expense', $plan->currency, $plan->category_id, $plan->name);

            return $payment;
        });
    }

    /** Only the latest installment can be undone, so numbering stays continuous. */
    public function deleteInstallment(InstallmentPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $last = InstallmentPayment::where('installment_plan_id', $payment->installment_plan_id)->max('number');
            if ($payment->number !== (int) $last) {
                throw ValidationException::withMessages(['payment' => 'Undo the latest installment first.']);
            }
            $this->unrecord($payment);
        });
    }

    public function deletePlan(InstallmentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $plan->payments->each(fn ($p) => $this->unrecord($p));
            $plan->delete();
        });
    }

    /** Pay this round's share (contribution) or take the pot (payout). */
    public function gameyaMove(Gameya $g, string $kind, array $data): GameyaPayment
    {
        return DB::transaction(function () use ($g, $kind, $data) {
            Gameya::whereKey($g->id)->lockForUpdate()->first();
            $g->load('payments');
            if ($kind === 'contribution') {
                $round = $g->nextRound() ?? throw ValidationException::withMessages(['round' => 'Every round is paid.']);
            } else {
                $g->received() && throw ValidationException::withMessages(['round' => 'The payout was already received.']);
                $round = $g->my_turn;
            }
            $payment = $g->payments()->create([
                'kind' => $kind, 'round' => $round,
                'amount' => $data['amount'] ?? ($kind === 'payout' ? $g->payout() : $g->amount),
                'date' => $data['date'] ?? CarbonImmutable::today()->toDateString(),
                'account_id' => array_key_exists('account_id', $data) ? $data['account_id'] : $g->account_id,
            ]);
            $this->record($payment, $g->user, $kind === 'payout' ? 'income' : 'expense', $g->currency, null, $g->name);

            return $payment;
        });
    }

    public function deleteGameyaPayment(GameyaPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            if ($payment->kind === 'contribution') {
                $last = GameyaPayment::where('gameya_id', $payment->gameya_id)->where('kind', 'contribution')->max('round');
                if ($payment->round !== (int) $last) {
                    throw ValidationException::withMessages(['payment' => 'Undo the latest round first.']);
                }
            }
            $this->unrecord($payment);
        });
    }

    public function deleteGameya(Gameya $g): void
    {
        DB::transaction(function () use ($g) {
            $g->payments->each(fn ($p) => $this->unrecord($p));
            $g->delete();
        });
    }

    private function record(InstallmentPayment|GameyaPayment $payment, $owner, string $type, string $currency, ?int $categoryId, string $note): void
    {
        if (! $payment->account_id) {
            return; // bookkeeping only
        }
        $account = Account::findOrFail($payment->account_id);
        $this->tx->create($owner, [
            'type' => $type, 'account_id' => $account->id, 'category_id' => $categoryId,
            'amount' => CurrencyConverter::for($owner)->convert($payment->amount, $currency, $account->currency),
            'date' => $payment->date->format('Y-m-d'), 'note' => $note,
            'source_type' => $payment->getMorphClass(), 'source_id' => $payment->id,
        ]);
    }

    private function unrecord(InstallmentPayment|GameyaPayment $payment): void
    {
        if ($payment->transaction) {
            $this->tx->delete($payment->transaction);
        }
        $payment->delete();
    }
}
