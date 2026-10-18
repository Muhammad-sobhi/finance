<?php

namespace App\Services;

use App\Exceptions\InsufficientFunds;
use App\Models\Account;
use App\Models\Bill;
use App\Models\IncomeSource;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Notifications\InfoAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurringService
{
    public function __construct(private readonly TransactionService $tx) {}

    /**
     * "Mark paid" (bills, subscriptions) or "received" (income sources): record the
     * transaction on the account and roll the due date to the next cycle.
     *
     * @param  array{account_id?: int|null, amount?: float|null, date?: string|null}  $data
     */
    public function pay(Bill|Subscription|IncomeSource $item, array $data = []): Transaction
    {
        $accountId = $data['account_id'] ?? $item->account_id;
        if (! $accountId) {
            throw ValidationException::withMessages(['account_id' => 'Choose the account.']);
        }

        return DB::transaction(function () use ($item, $data, $accountId) {
            $account = Account::findOrFail($accountId);
            $amount = (float) ($data['amount'] ?? $item->amount);
            $date = $data['date'] ?? CarbonImmutable::today()->toDateString();

            $tx = $this->tx->create($item->user, [
                'type' => $item::TX_TYPE, // expense for bills/subscriptions, income for income sources
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

    /**
     * Pay every active auto-pay item that is due (catching up missed cycles). Returns count paid.
     * An item whose account is short stays unpaid (tried again tomorrow) and the owner is told,
     * once per due date.
     */
    public function runAutoPay(): int
    {
        $today = CarbonImmutable::today();
        $paid = 0;
        foreach ([Bill::class, Subscription::class, IncomeSource::class] as $class) {
            $class::query()->where('is_active', true)->where('auto_pay', true)->whereNotNull('account_id')
                ->where($class::DUE_COLUMN, '<=', $today->toDateString())
                ->each(function (Bill|Subscription|IncomeSource $item) use ($today, &$paid) {
                    for ($guard = 0; $guard < 24 && $item->is_active && $item->dueDate()->lte($today); $guard++) {
                        try {
                            $this->pay($item, ['date' => $item->dueDate()->toDateString()]);
                        } catch (InsufficientFunds $e) {
                            $this->reportShort($item, $e);
                            break;
                        }
                        $paid++;
                    }
                });
        }

        return $paid;
    }

    private function reportShort(Bill|Subscription|IncomeSource $item, InsufficientFunds $e): void
    {
        $due = $item->dueDate()->toDateString();
        if (! Cache::add("autopay-short:{$item->getMorphClass()}:{$item->id}:{$due}", true, now()->addDays(40))) {
            return;
        }
        $item->user->notify(new InfoAlert('autopay_short', [
            'item' => $item->name, 'account' => $e->account->name, 'currency' => $e->account->currency,
            'amount' => (float) $item->amount, 'item_currency' => $item->currency,
            'available' => $e->available, 'shortfall' => $e->shortfall,
        ], $item instanceof Subscription ? '/subscriptions' : '/bills'));
    }
}
