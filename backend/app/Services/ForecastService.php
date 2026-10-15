<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Bill;
use App\Models\IncomeSource;
use App\Models\InstallmentPlan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Month-end forecast: what's spent so far + everyday spending at the current pace for
 * the days left + scheduled payments still due this month (bills, subscriptions,
 * installments); income received + income still expected.
 */
class ForecastService
{
    /** Expenses that follow a schedule; they are counted from the schedule, not the daily pace. */
    public const SCHEDULED_SOURCES = ['bill', 'subscription', 'installment_payment', 'zakat_payment'];

    /** Below this many days the month's own pace is too noisy; use the 3-month average instead. */
    public const MIN_DAYS = 7;

    public function month(User $user, ?CarbonImmutable $today = null): array
    {
        $owner = $user->dataOwner();
        $today ??= CarbonImmutable::today();
        $fx = CurrencyConverter::for($owner);
        $start = $today->startOfMonth();
        $end = $today->endOfMonth()->startOfDay();
        $daysLeft = $end->day - $today->day;

        $txs = Transaction::ownedBy($owner)->cashflow()->whereBetween('date', [$start->toDateString(), $today->toDateString()])
            ->where('type', '!=', TransactionType::Transfer->value)->get(['type', 'amount', 'currency', 'source_type']);
        $base = fn ($t) => $fx->toBase($t->amount, $t->currency);
        $expenses = $txs->where('type', TransactionType::Expense);
        $spent = $expenses->sum($base);
        $variable = $expenses->filter(fn ($t) => ! in_array($t->source_type, self::SCHEDULED_SOURCES, true))->sum($base);
        $income = $txs->where('type', TransactionType::Income)->sum($base);

        $rate = $today->day >= self::MIN_DAYS ? $variable / $today->day : $this->historicRate($owner, $start, $fx) ?? $variable / $today->day;

        // Scheduled payments still to come this month (overdue ones included: they'll still be paid).
        $scheduled = 0.0;
        foreach ([Bill::class, Subscription::class] as $class) {
            $class::ownedBy($owner)->where('is_active', true)->where($class::DUE_COLUMN, '<=', $end->toDateString())->get()
                ->each(function ($item) use (&$scheduled, $end, $fx) {
                    $scheduled += $this->occurrences($item, $end) * $fx->toBase($item->amount, $item->currency);
                });
        }
        $scheduled += InstallmentPlan::ownedBy($owner)->withCount('payments')->get()
            ->sum(fn (InstallmentPlan $p) => $fx->toBase($p->remainingUntil($end), $p->currency));

        $expected = IncomeSource::ownedBy($owner)->where('is_active', true)->where('next_date', '<=', $end->toDateString())->get()
            ->sum(fn (IncomeSource $s) => $this->occurrences($s, $end) * $fx->toBase($s->amount, $s->currency));
        $expected += Project::ownedBy($owner)->where('status', 'active')->whereNotNull('due_date')->where('due_date', '<=', $end->toDateString())
            ->withSum('payments', 'amount')->get()->sum(fn (Project $p) => $fx->toBase($p->outstanding(), $p->currency));

        $r = fn ($v) => round((float) $v, 2);
        $projectedSpend = $spent + $rate * $daysLeft + $scheduled;
        $projectedIncome = $income + $expected;

        return [
            'month' => $start->format('Y-m'), 'days_left' => $daysLeft,
            'spent' => $r($spent), 'daily_rate' => $r($rate), 'scheduled' => $r($scheduled), 'projected_spend' => $r($projectedSpend),
            'income' => $r($income), 'expected_income' => $r($expected), 'projected_income' => $r($projectedIncome),
            'projected_savings' => $r($projectedIncome - $projectedSpend),
        ];
    }

    /** How many times a recurring item falls due up to `$end` (weekly items can repeat). */
    private function occurrences(Bill|Subscription|IncomeSource $item, CarbonImmutable $end): int
    {
        $n = 0;
        for ($d = $item->dueDate(); $d !== null && $d->lte($end) && $n < 6; $d = $item->cycle->next($d)) {
            $n++;
        }

        return $n;
    }

    /** Average everyday spending per day over the previous 3 full months; null without history. */
    private function historicRate(User $owner, CarbonImmutable $start, CurrencyConverter $fx): ?float
    {
        $from = $start->subMonthsNoOverflow(3);
        $rows = Transaction::ownedBy($owner)->cashflow()->where('type', TransactionType::Expense->value)
            ->whereBetween('date', [$from->toDateString(), $start->subDay()->toDateString()])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhereNotIn('source_type', self::SCHEDULED_SOURCES))
            ->get(['amount', 'currency']);
        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->sum(fn ($t) => $fx->toBase($t->amount, $t->currency)) / $from->diffInDays($start);
    }
}
