<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\NetWorthSnapshot;
use App\Models\Transaction;
use App\Services\AlertScanner;
use App\Services\CurrencyConverter;
use App\Services\DueItem;
use App\Services\ForecastService;
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

        // Change vs the end of last month, from the monthly snapshots.
        $prev = NetWorthSnapshot::ownedBy($user)->where('month', $today->startOfMonth()->subMonth()->toDateString())->first()?->net_worth;
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
            'installments' => $nw['installments'],
            'gameya' => $nw['gameya'],
            'balances' => $accounts->sortByDesc('base_balance')->values()->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type->value,
                'currency' => $a->currency,
                'balance' => (float) $a->balance,
                'base_balance' => $a->base_balance,
            ]),
            'spending' => $this->monthly($user, $fx, $today),
            'upcoming' => $this->upcoming($user, $today),
            'forecast' => app(ForecastService::class)->month($user, $today),
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
            ->get(['user_id', 'type', 'amount', 'currency', 'date'])
            ->each(function (Transaction $tx) use (&$months, $fx) {
                $key = $tx->date->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key][$tx->type->value] = round($months[$key][$tx->type->value] + $fx->toBase($tx->amount, $tx->currency), 2);
                }
            });

        return array_values($months);
    }

    /** Everything due within 30 days or overdue — the same items the alert scanner watches. */
    private function upcoming($user, CarbonImmutable $today): array
    {
        $horizon = $today->addDays(30);

        return app(AlertScanner::class)->items($user->dataOwner(), $today)
            ->filter(fn (DueItem $i) => $i->due->lte($horizon))
            ->map(fn (DueItem $i) => [
                'kind' => $i->kind, 'id' => $i->id, 'name' => $i->name, 'due_date' => $i->due->toDateString(),
                'days' => $i->days($today), 'amount' => round($i->amount, 2), 'currency' => $i->currency,
            ])
            ->sortBy('days')->values()->all();
    }
}
