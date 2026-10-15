<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Bill;
use App\Models\Debt;
use App\Models\Investment;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $fx = CurrencyConverter::for($user);
        $today = CarbonImmutable::today();

        $accounts = Account::ownedBy($user)->where('is_active', true)->get()
            ->each(fn (Account $a) => $a->base_balance = $fx->toBase($a->balance, $a->currency));

        $owner = $user->dataOwner();
        $debts = Debt::ownedBy($user)->where('status', DebtStatus::Open)->withSum('payments', 'amount')->get();
        $owed = round($debts->where('direction', DebtDirection::OwedToMe)->sum(fn (Debt $d) => $fx->toBase($d->remaining(), $d->currency)), 2);
        $owe = round($debts->where('direction', DebtDirection::IOwe)->sum(fn (Debt $d) => $fx->toBase($d->remaining(), $d->currency)), 2);
        $investments = Investment::ownedBy($user)->get();
        $invested = round($investments->sum(fn (Investment $i) => $fx->toBase($i->current_value, $i->currency)), 2);
        $assets = round(Asset::ownedBy($user)->get()->sum(function (Asset $a) use ($owner, $fx) {
            [$value, $currency] = $a->valuation($owner);

            return $fx->toBase($value, $currency);
        }), 2);

        $netWorth = round($accounts->sum('base_balance') + $invested + $assets + $owed - $owe, 2);
        $liquid = round($accounts->filter(fn (Account $a) => $a->type->isLiquid() && $a->base_balance > 0)->sum('base_balance'), 2);

        return response()->json([
            'base_currency' => $fx->base,
            'net_worth' => $netWorth,
            'liquid' => $liquid,
            'owed_to_me' => $owed,
            'i_owe' => $owe,
            'investments' => $invested,
            'assets' => $assets,
            'balances' => $accounts->sortByDesc('base_balance')->values()->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type->value,
                'currency' => $a->currency,
                'balance' => (float) $a->balance,
                'base_balance' => $a->base_balance,
            ]),
            'spending' => $this->monthly($user, $fx, $today),
            'upcoming' => $this->upcoming($user, $accounts, $debts, $investments, $today),
        ]);
    }

    /** Expense and income per month for the last 6 months (current month last). */
    private function monthly($user, CurrencyConverter $fx, CarbonImmutable $today): array
    {
        $from = $today->startOfMonth()->subMonths(5);
        $months = [];
        for ($m = $from; $m <= $today; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'expense' => 0.0, 'income' => 0.0];
        }

        Transaction::ownedBy($user)->cashflow()
            ->where('date', '>=', $from->toDateString())
            ->where('type', '!=', TransactionType::Transfer->value)
            ->get(['type', 'amount', 'currency', 'date'])
            ->each(function (Transaction $tx) use (&$months, $fx) {
                $key = $tx->date->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key][$tx->type->value] = round($months[$key][$tx->type->value] + $fx->toBase($tx->amount, $tx->currency), 2);
                }
            });

        return array_values($months);
    }

    /** Everything due within 30 days or overdue: bills, subscriptions, debts, maturities, card payments. */
    private function upcoming($user, $accounts, $debts, $investments, CarbonImmutable $today): array
    {
        $horizon = $today->addDays(30);
        $item = fn (string $kind, int $id, string $name, CarbonImmutable $due, float $amount, string $currency) => [
            'kind' => $kind, 'id' => $id, 'name' => $name, 'due_date' => $due->toDateString(),
            'days' => (int) $today->diffInDays($due, false), 'amount' => $amount, 'currency' => $currency,
        ];
        $out = collect();

        foreach ([Bill::class => 'bill', Subscription::class => 'subscription'] as $class => $kind) {
            $class::ownedBy($user)->where('is_active', true)->where($class::DUE_COLUMN, '<=', $horizon->toDateString())->get()
                ->each(fn ($r) => $out->push($item($kind, $r->id, $r->name, $r->dueDate(), (float) $r->amount, $r->currency)));
        }
        $debts->filter(fn (Debt $d) => $d->due_date && $d->due_date->lte($horizon))
            ->each(fn (Debt $d) => $out->push($item($d->direction->value, $d->id, $d->person, CarbonImmutable::parse($d->due_date), $d->remaining(), $d->currency)));
        $investments->filter(fn (Investment $i) => $i->maturity_date && $i->maturity_date->between($today, $horizon))
            ->each(fn (Investment $i) => $out->push($item('maturity', $i->id, $i->name, CarbonImmutable::parse($i->maturity_date), (float) $i->current_value, $i->currency)));

        $accounts->filter(fn (Account $a) => $a->type === AccountType::CreditCard && $a->due_day && $a->balance < 0)
            ->each(function (Account $a) use ($today, $item, $out) {
                $due = $today->setDay(min($a->due_day, $today->daysInMonth));
                if ($due->lt($today)) {
                    $next = $today->startOfMonth()->addMonth();
                    $due = $next->setDay(min($a->due_day, $next->daysInMonth));
                }
                if ($due->diffInDays($today, true) <= 30) {
                    $out->push($item('credit_card', $a->id, $a->name, $due, abs((float) $a->balance), $a->currency));
                }
            });

        return $out->sortBy('days')->values()->all();
    }
}
