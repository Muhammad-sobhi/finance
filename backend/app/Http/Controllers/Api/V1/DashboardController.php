<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Debt;
use App\Models\IncomeSource;
use App\Models\Investment;
use App\Models\NetWorthSnapshot;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\NetWorth;
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

        $nw = app(NetWorth::class)->compute($user);
        $debts = Debt::ownedBy($user)->where('status', DebtStatus::Open)->withSum('payments', 'amount')->get();
        $investments = Investment::ownedBy($user)->get();

        // Change vs the end of last month, from the monthly snapshots.
        $prev = NetWorthSnapshot::ownedBy($user)->where('month', $today->startOfMonth()->subMonth()->toDateString())->value('net_worth');
        $change = $prev !== null && (float) $prev != 0.0 ? round(($nw['net_worth'] - (float) $prev) / abs((float) $prev) * 100, 1) : null;

        return response()->json([
            'base_currency' => $fx->base,
            'net_worth' => $nw['net_worth'],
            'net_worth_change_pct' => $change,
            'liquid' => $nw['liquid'],
            'owed_to_me' => $nw['owed_to_me'],
            'i_owe' => $nw['i_owe'],
            'investments' => $nw['investments'],
            'assets' => $nw['assets'],
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
        IncomeSource::ownedBy($user)->where('is_active', true)->where('next_date', '<=', $horizon->toDateString())->get()
            ->each(fn (IncomeSource $r) => $out->push($item('income', $r->id, $r->name, $r->dueDate(), (float) $r->amount, $r->currency)));
        Project::ownedBy($user)->where('status', 'active')->whereNotNull('due_date')->where('due_date', '<=', $horizon->toDateString())
            ->withSum('payments', 'amount')->get()->filter(fn (Project $p) => $p->outstanding() > 0)
            ->each(fn (Project $p) => $out->push($item('project', $p->id, "{$p->client} — {$p->name}", CarbonImmutable::parse($p->due_date), $p->outstanding(), $p->currency)));
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
