<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurringService
{
    public function __construct(private readonly TransactionService $tx) {}

    /**
     * "Mark paid": record an expense from the pay-from account and roll the due date.
     *
     * @param  array{account_id?: int|null, amount?: float|null, date?: string|null}  $data
     */
    public function pay(Bill|Subscription $item, array $data = []): Transaction
    {
        $accountId = $data['account_id'] ?? $item->account_id;
        if (! $accountId) {
            throw ValidationException::withMessages(['account_id' => 'Choose the account to pay from.']);
        }

        return DB::transaction(function () use ($item, $data, $accountId) {
            $account = Account::findOrFail($accountId);
            $amount = (float) ($data['amount'] ?? $item->amount);
            $date = $data['date'] ?? CarbonImmutable::today()->toDateString();

            $tx = $this->tx->create($item->user, [
                'type' => 'expense',
                'account_id' => $account->id,
                'category_id' => $item->category_id,
                'amount' => CurrencyConverter::for($item->user)->convert($amount, $item->currency, $account->currency),
                'date' => $date,
                'note' => $item->name,
                'source_type' => $item->getMorphClass(),
                'source_id' => $item->id,
            ]);

            $item->last_paid_at = $date;
            $item->roll();
            $item->save();

            return $tx;
        });
    }

    /** Pay every active auto-pay item that is due (catching up missed cycles). Returns count paid. */
    public function runAutoPay(): int
    {
        $today = CarbonImmutable::today();
        $paid = 0;
        foreach ([Bill::class, Subscription::class] as $class) {
            $class::query()->where('is_active', true)->where('auto_pay', true)->whereNotNull('account_id')
                ->where($class::DUE_COLUMN, '<=', $today->toDateString())
                ->each(function (Bill|Subscription $item) use ($today, &$paid) {
                    for ($guard = 0; $guard < 24 && $item->is_active && $item->dueDate()->lte($today); $guard++) {
                        $this->pay($item, ['date' => $item->dueDate()->toDateString()]);
                        $paid++;
                    }
                });
        }

        return $paid;
    }
}
