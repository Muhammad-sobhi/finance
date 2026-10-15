<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InfoAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Monthly category budgets: spending so far (in base currency) and the 80% / 100% warnings. */
class BudgetService
{
    public const NEAR_PCT = 80;

    /** @return list<array<string, mixed>> */
    public function status(User $user, CarbonImmutable $month): array
    {
        $owner = $user->dataOwner();
        $fx = CurrencyConverter::for($owner);
        $start = $month->startOfMonth();
        $spent = $this->spentByCategory($owner, $start, $fx);

        $today = CarbonImmutable::today();
        $current = $start->isSameMonth($today);
        $daysIn = $start->daysInMonth;
        $elapsed = $current ? $today->day : ($start->lt($today) ? $daysIn : 0);

        return Budget::ownedBy($owner)->with('category')->get()->map(function (Budget $b) use ($spent, $current, $daysIn, $elapsed) {
            $limit = (float) $b->amount;
            $used = round($spent[$b->category_id] ?? 0, 2);
            $pct = $limit > 0 ? round($used / $limit * 100, 1) : 0.0;

            return [
                'id' => $b->id, 'category_id' => $b->category_id, 'amount' => $limit,
                'spent' => $used, 'remaining' => round($limit - $used, 2), 'pct' => $pct,
                'status' => $used > $limit ? 'over' : ($pct >= self::NEAR_PCT ? 'near' : 'ok'),
                // Linear pace to month end; only meaningful while the month is running.
                'projected' => $current && $elapsed > 0 ? round($used / $elapsed * $daysIn, 2) : null,
                // How much can still go out per remaining day without breaking the limit.
                'per_day_left' => $current ? round(max(0, $limit - $used) / ($daysIn - $elapsed + 1), 2) : null,
            ];
        })->sortByDesc('pct')->values()->all();
    }

    /** Send each budget's "near" and "over" notice at most once per month. Returns notices sent. */
    public function watch(User $owner, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $base = $owner->base_currency;
        $sent = 0;
        foreach ($this->status($owner, $today) as $b) {
            if ($b['status'] === 'ok') {
                continue;
            }
            $fresh = DB::table('alert_logs')->insertOrIgnore([
                'user_id' => $owner->id, 'kind' => 'budget', 'item_id' => $b['id'], 'stage' => $b['status'],
                'due_date' => $today->startOfMonth()->toDateString(), 'sent_at' => now(),
            ]);
            if (! $fresh) {
                continue;
            }
            $category = $owner->categories()->find($b['category_id']);
            $owner->notify(new InfoAlert("budget_{$b['status']}", [
                'category' => $category?->key
                    ? ['ar' => __("notices.categories.{$category->key}", [], 'ar'), 'en' => __("notices.categories.{$category->key}", [], 'en')]
                    : (string) $category?->name,
                'spent' => $b['spent'], 'limit' => $b['amount'], 'over' => round($b['spent'] - $b['amount'], 2),
                'pct' => (int) floor($b['pct']), 'currency' => $base,
            ], '/planning'));
            $sent++;
        }

        return $sent;
    }

    /** @return array<int, float> category_id => spent in base */
    public function spentByCategory(User $owner, CarbonImmutable $start, CurrencyConverter $fx): array
    {
        $out = [];
        Transaction::ownedBy($owner)->cashflow()->where('type', TransactionType::Expense)->whereNotNull('category_id')
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->selectRaw('category_id, currency, sum(amount) as total')->groupBy('category_id', 'currency')->get()
            ->each(function ($row) use (&$out, $fx) {
                $out[$row->category_id] = ($out[$row->category_id] ?? 0) + $fx->toBase($row->total, $row->currency);
            });

        return $out;
    }
}
