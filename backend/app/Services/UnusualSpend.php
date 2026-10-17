<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InfoAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Flags a hand-entered expense far above what that category usually costs. */
class UnusualSpend
{
    public const FACTOR = 3;          // × the category's median

    public const MIN_SAMPLES = 5;     // needs some history to judge

    public const WINDOW_DAYS = 90;

    /** @return bool whether a notice was sent */
    public function check(User $owner, Transaction $tx): bool
    {
        if ($tx->type !== TransactionType::Expense || $tx->source_type !== null || ! $tx->category_id) {
            return false;
        }
        $fx = CurrencyConverter::for($owner);
        $date = CarbonImmutable::parse($tx->date);
        $history = Transaction::ownedBy($owner)->where('type', TransactionType::Expense->value)->whereNull('source_type')
            ->where('category_id', $tx->category_id)->whereKeyNot($tx->id)
            ->whereBetween('date', [$date->subDays(self::WINDOW_DAYS)->toDateString(), $date->toDateString()])
            ->get(['user_id', 'amount', 'currency'])->map(fn ($t) => $fx->toBase($t->amount, $t->currency))->sort()->values();
        if ($history->count() < self::MIN_SAMPLES) {
            return false;
        }
        $median = (float) $history->median();
        $amount = $fx->toBase($tx->amount, $tx->currency);
        if ($median <= 0 || $amount <= $median * self::FACTOR) {
            return false;
        }
        $fresh = DB::table('alert_logs')->insertOrIgnore([
            'user_id' => $owner->id, 'kind' => 'unusual', 'item_id' => $tx->id, 'stage' => 'once',
            'due_date' => $date->toDateString(), 'sent_at' => now(),
        ]);
        if (! $fresh) {
            return false;
        }
        $category = $tx->category;
        $owner->notify(new InfoAlert('unusual_expense', [
            'category' => $category?->key
                ? ['ar' => __("notices.categories.{$category->key}", [], 'ar'), 'en' => __("notices.categories.{$category->key}", [], 'en')]
                : (string) $category?->name,
            'amount' => $amount, 'median' => round($median, 2), 'times' => (int) floor($amount / $median), 'currency' => $fx->base,
        ], '/transactions'));

        return true;
    }
}
