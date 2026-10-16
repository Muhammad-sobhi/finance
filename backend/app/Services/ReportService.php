<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\NetWorthSnapshot;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly / yearly reports: totals, savings rate, spending by category, income vs
 * spending series, net-worth trend, comparison with the previous period, and advice.
 * Loans/repayments and transfers are not income or spending.
 */
class ReportService
{
    /** Thresholds for the advice rules. */
    public const MIN_SAVINGS_RATE = 20;

    public const CATEGORY_SPIKE = 25;

    public const SUBSCRIPTION_SHARE = 10;

    public const IDLE_CASH_MONTHS = 6;

    public const CARD_UTILIZATION = 30;

    private CurrencyConverter $fx;

    private User $owner;

    private ?array $live = null;

    public function __construct(private readonly NetWorth $netWorth) {}

    public function build(User $user, string $period, string $value, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $this->owner = $user->dataOwner();
        $this->live = null;
        $this->fx = CurrencyConverter::for($this->owner);

        [$start, $end] = $this->range($period, $value);
        [$pStart, $pEnd] = $period === 'year' ? [$start->subYear(), $end->subYear()] : [$start->subMonthNoOverflow(), $start->subDay()];

        $cur = $this->totals($start, $end);
        $prev = $this->totals($pStart, $pEnd);
        $byCat = $this->byCategory($start, $end, $pStart, $pEnd, $cur['expense']);

        $nwEnd = $this->netWorthAt($end, $today);
        $nwPrev = $this->netWorthAt($pEnd, $today);
        $growth = $nwEnd !== null && $nwPrev !== null && $nwPrev != 0.0
            ? ['basis' => 'net_worth', 'pct' => $this->pct($nwEnd, $nwPrev), 'growing' => $nwEnd > $nwPrev, 'value' => $nwEnd, 'previous' => $nwPrev]
            : ['basis' => 'savings', 'pct' => $this->pct($cur['savings'], $prev['savings']), 'growing' => $cur['savings'] > 0 && $cur['savings'] >= $prev['savings'], 'value' => $cur['savings'], 'previous' => $prev['savings']];

        return [
            'period' => $period,
            'value' => $value,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'base_currency' => $this->fx->base,
            'totals' => $cur,
            'previous' => $prev,
            'change' => [
                'income' => $this->pct($cur['income'], $prev['income']),
                'expense' => $this->pct($cur['expense'], $prev['expense']),
                'savings' => $this->pct($cur['savings'], $prev['savings']),
            ],
            'growth' => $growth,
            'by_category' => $byCat,
            'series' => $this->series($period, $start, $end),
            'net_worth_trend' => $this->trend($end, $today),
            'advice' => $this->advice($period, $start, $end, $cur, $byCat, $today),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $period, string $value): array
    {
        if ($period === 'year') {
            $start = CarbonImmutable::create((int) $value, 1, 1);

            return [$start, $start->endOfYear()->startOfDay()];
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $value);

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    private function cashflow(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Transaction::ownedBy($this->owner)->cashflow()
            ->where('type', '!=', TransactionType::Transfer->value)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['type', 'amount', 'currency', 'date', 'category_id']);
    }

    /** @return array{income: float, expense: float, savings: float, savings_rate: float|null} */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->cashflow($from, $to);
        $sum = fn (string $type) => round($rows->where('type', TransactionType::from($type))->sum(fn ($t) => $this->fx->toBase($t->amount, $t->currency)), 2);
        $income = $sum('income');
        $expense = $sum('expense');
        $savings = round($income - $expense, 2);

        return ['income' => $income, 'expense' => $expense, 'savings' => $savings, 'savings_rate' => $income > 0 ? round($savings / $income * 100, 1) : null];
    }

    private function byCategory(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $pFrom, CarbonImmutable $pTo, float $total): array
    {
        $group = fn (Collection $rows) => $rows->where('type', TransactionType::Expense)
            ->groupBy(fn ($t) => $t->category_id ?? 0)
            ->map(fn ($g) => round($g->sum(fn ($t) => $this->fx->toBase($t->amount, $t->currency)), 2));
        $now = $group($this->cashflow($from, $to));
        $before = $group($this->cashflow($pFrom, $pTo));
        $cats = Category::ownedBy($this->owner)->get()->keyBy('id');

        return $now->sortDesc()->map(fn ($amount, $id) => [
            'category_id' => $id ?: null,
            'key' => $cats[$id]->key ?? ($id ? null : 'uncategorized'),
            'name' => $cats[$id]->name ?? null,
            'color' => $cats[$id]->color ?? null,
            'icon' => $cats[$id]->icon ?? null,
            'amount' => $amount,
            'share' => $total > 0 ? round($amount / $total * 100, 1) : 0,
            'previous' => $before[$id] ?? 0,
            'change' => $this->pct($amount, $before[$id] ?? 0),
        ])->values()->all();
    }

    /** Month: the 6 months ending with this one. Year: its 12 months. */
    private function series(string $period, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $period === 'year' ? $start : $start->subMonthsNoOverflow(5);
        $months = [];
        for ($m = $from; $m <= $end; $m = $m->addMonthNoOverflow()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'income' => 0.0, 'expense' => 0.0];
        }
        foreach ($this->cashflow($from, $end) as $t) {
            $k = $t->date->format('Y-m');
            $months[$k][$t->type->value] = round($months[$k][$t->type->value] + $this->fx->toBase($t->amount, $t->currency), 2);
        }

        return array_values($months);
    }

    /** Snapshot value at the month containing $date; the current month falls back to a live figure. */
    private function netWorthAt(CarbonImmutable $date, CarbonImmutable $today): ?float
    {
        if ($date->format('Y-m') === $today->format('Y-m')) {
            return $this->liveNetWorth()['net_worth'];
        }
        if ($date->gt($today)) {
            return null;
        }
        $value = NetWorthSnapshot::ownedBy($this->owner)->where('month', '<=', $date->startOfMonth()->toDateString())
            ->orderByDesc('month')->value('net_worth');

        return $value !== null ? (float) $value : null;
    }

    private function trend(CarbonImmutable $end, CarbonImmutable $today): array
    {
        $points = NetWorthSnapshot::ownedBy($this->owner)->where('month', '<=', $end->startOfMonth()->toDateString())
            ->orderByDesc('month')->limit(12)->get()->reverse()
            ->map(fn ($s) => ['month' => $s->month->format('Y-m'), 'net_worth' => (float) $s->net_worth])->values();
        // Make the current month live even before tonight's snapshot.
        if ($end->gte($today->startOfMonth())) {
            $points = $points->reject(fn ($p) => $p['month'] === $today->format('Y-m'))
                ->push(['month' => $today->format('Y-m'), 'net_worth' => $this->liveNetWorth()['net_worth']])
                ->take(-12)->values();
        }

        return $points->all();
    }

    /** Today's net worth, computed once per report (it reads every account, debt, asset…). */
    private function liveNetWorth(): array
    {
        return $this->live ??= $this->netWorth->compute($this->owner);
    }

    private function pct(float $now, float $before): ?float
    {
        return $before != 0.0 ? round(($now - $before) / abs($before) * 100, 1) : null;
    }

    /** Rule-based advice; each item has text in both languages. */
    private function advice(string $period, CarbonImmutable $start, CarbonImmutable $end, array $cur, array $byCat, CarbonImmutable $today): array
    {
        $out = [];
        $add = function (string $code, string $level, array $params) use (&$out) {
            $out[] = ['code' => $code, 'level' => $level, 'params' => $params, 'text' => [
                'ar' => __("advice.{$code}", $this->localize($params, 'ar'), 'ar'),
                'en' => __("advice.{$code}", $this->localize($params, 'en'), 'en'),
            ]];
        };
        $base = $this->fx->base;
        $months = $period === 'year'
            ? max(1, $end->lte($today) ? 12 : $today->month)
            : 1;
        $monthlyIncome = $cur['income'] / $months;

        if ($cur['expense'] > $cur['income'] && $cur['income'] > 0) {
            $add('overspending', 'danger', ['gap' => round($cur['expense'] - $cur['income']), 'currency' => $base]);
        } elseif ($cur['savings_rate'] !== null && $cur['savings_rate'] < self::MIN_SAVINGS_RATE) {
            $add('low_savings', 'warn', ['rate' => $cur['savings_rate']]);
        }

        // Category spikes vs the average of the 3 months before (monthly reports).
        if ($period === 'month') {
            $hist = $this->cashflow($start->subMonthsNoOverflow(3), $start->subDay())->where('type', TransactionType::Expense)
                ->groupBy(fn ($t) => $t->category_id ?? 0)
                ->map(fn ($g) => $g->sum(fn ($t) => $this->fx->toBase($t->amount, $t->currency)) / 3);
            $spikes = collect($byCat)->filter(function ($c) use ($hist) {
                $avg = $hist[$c['category_id'] ?? 0] ?? 0;

                return $avg > 0 && $c['amount'] > $avg * (1 + self::CATEGORY_SPIKE / 100);
            })->map(fn ($c) => $c + ['avg_pct' => round(($c['amount'] / $hist[$c['category_id'] ?? 0] - 1) * 100)])
                ->sortByDesc('avg_pct')->take(3);
            foreach ($spikes as $c) {
                $add('category_up', 'warn', ['category' => ['key' => $c['key'], 'name' => $c['name']], 'pct' => $c['avg_pct']]);
            }
        }

        // The running month: warn early when the forecast ends below zero.
        if ($period === 'month' && $start->isSameMonth($today)) {
            $f = app(ForecastService::class)->month($this->owner, $today);
            if ($f['projected_savings'] < 0 && $f['days_left'] > 0) {
                $add('forecast_negative', 'warn', ['gap' => round(-$f['projected_savings']), 'currency' => $base]);
            }
        }

        $subs = Subscription::ownedBy($this->owner)->where('is_active', true)->get()
            ->sum(fn (Subscription $s) => $this->fx->toBase($s->monthlyCost(), $s->currency));
        if ($monthlyIncome > 0 && $subs / $monthlyIncome * 100 > self::SUBSCRIPTION_SHARE) {
            $add('subscriptions_high', 'info', ['pct' => round($subs / $monthlyIncome * 100)]);
        }

        $nw = $this->liveNetWorth();
        $avgSpend = $this->totals($today->startOfMonth()->subMonthsNoOverflow(3), $today->startOfMonth()->subDay())['expense'] / 3;
        if ($avgSpend > 0 && $nw['liquid'] > $avgSpend * self::IDLE_CASH_MONTHS) {
            $add('idle_cash', 'info', ['months' => (int) floor($nw['liquid'] / $avgSpend)]);
        }

        Account::ownedBy($this->owner)->where('type', AccountType::CreditCard)->where('is_active', true)->where('credit_limit', '>', 0)->get()
            ->each(function (Account $a) use ($add) {
                $used = max(0, -(float) $a->balance) / (float) $a->credit_limit * 100;
                if ($used > self::CARD_UTILIZATION) {
                    $add('card_utilization', 'warn', ['name' => $a->name, 'pct' => round($used)]);
                }
            });

        Debt::ownedBy($this->owner)->where('status', DebtStatus::Open)->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())->withSum('payments', 'amount')->get()
            ->each(fn (Debt $d) => $add(
                $d->direction === DebtDirection::IOwe ? 'debt_overdue' : 'collect_debt',
                $d->direction === DebtDirection::IOwe ? 'danger' : 'info',
                ['name' => $d->person, 'amount' => round($d->remaining()), 'currency' => $d->currency],
            ));

        $rank = ['danger' => 0, 'warn' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);

        return $out;
    }

    /** Turn structured params into display strings for one language. */
    private function localize(array $params, string $locale): array
    {
        return collect($params)->map(function ($v) use ($locale) {
            if (is_array($v)) { // category
                return $v['key'] ? __("advice.categories.{$v['key']}", [], $locale) : $v['name'];
            }

            return is_float($v) || is_int($v) ? number_format($v, is_float($v) && floor($v) != $v ? 1 : 0) : $v;
        })->all();
    }
}
