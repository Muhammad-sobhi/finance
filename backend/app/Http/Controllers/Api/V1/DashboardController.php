<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Account;
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

        $netWorth = round($accounts->sum('base_balance'), 2);
        $liquid = round($accounts->filter(fn (Account $a) => $a->type->isLiquid() && $a->base_balance > 0)->sum('base_balance'), 2);

        return response()->json([
            'base_currency' => $fx->base,
            'net_worth' => $netWorth,
            'liquid' => $liquid,
            // Filled by the debts module.
            'owed_to_me' => 0,
            'i_owe' => 0,
            'balances' => $accounts->sortByDesc('base_balance')->values()->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type->value,
                'currency' => $a->currency,
                'balance' => (float) $a->balance,
                'base_balance' => $a->base_balance,
            ]),
            'spending' => $this->monthly($user, $fx, $today),
            'upcoming' => $this->upcoming($accounts, $today),
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

        Transaction::ownedBy($user)
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

    /** Credit-card due dates in the next 30 days; other modules add bills, debts, etc. */
    private function upcoming($accounts, CarbonImmutable $today): array
    {
        return $accounts
            ->filter(fn (Account $a) => $a->type === AccountType::CreditCard && $a->due_day && $a->balance < 0)
            ->map(function (Account $a) use ($today) {
                $due = $today->setDay(min($a->due_day, $today->daysInMonth));
                if ($due->lt($today)) {
                    $next = $today->startOfMonth()->addMonth();
                    $due = $next->setDay(min($a->due_day, $next->daysInMonth));
                }

                return [
                    'kind' => 'credit_card',
                    'id' => $a->id,
                    'name' => $a->name,
                    'due_date' => $due->toDateString(),
                    'days' => (int) $today->diffInDays($due),
                    'amount' => abs((float) $a->balance),
                    'currency' => $a->currency,
                ];
            })
            ->filter(fn ($u) => $u['days'] <= 30)
            ->sortBy('days')->values()->all();
    }
}
