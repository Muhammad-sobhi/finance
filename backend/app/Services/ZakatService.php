<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Debt;
use App\Models\Gameya;
use App\Models\InstallmentPlan;
use App\Models\Investment;
use App\Models\User;
use App\Models\ZakatPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Zakat al-mal estimate: zakatable wealth (cash, gold/silver/jewelry/foreign cash,
 * zakatable investments, money owed to you) minus what you owe, compared with the nisab.
 * It is a helper, not a fatwa — the UI says so.
 */
class ZakatService
{
    public const GOLD_NISAB_GRAMS = 85;     // 24k

    public const SILVER_NISAB_GRAMS = 595;

    public const RATE_HIJRI = 0.025;

    /** 2.5% scaled to a solar year: 2.5 × 365.25 / 354.37. */
    public const RATE_GREGORIAN = 0.02577;

    public function __construct(private readonly TransactionService $tx) {}

    /** @return array<string, mixed> */
    public function compute(User $user, ?CarbonImmutable $today = null): array
    {
        $owner = $user->dataOwner();
        $today ??= CarbonImmutable::today();
        $fx = CurrencyConverter::for($owner);
        $r = fn ($v) => round((float) $v, 2);

        $accounts = Account::ownedBy($owner)->where('is_active', true)->get();
        $cash = $accounts->where('balance', '>', 0)->sum(fn (Account $a) => $fx->toBase($a->balance, $a->currency));
        $overdrawn = $accounts->where('balance', '<', 0)->sum(fn (Account $a) => $fx->toBase(-$a->balance, $a->currency));

        $metals = 0.0;
        $otherAssets = 0.0;
        foreach (Asset::ownedBy($owner)->get()->filter->isZakatable() as $a) {
            [$value, $currency] = $a->valuation($owner);
            if ($a->type->isMetal() || $a->type === AssetType::Jewelry) {
                $metals += $fx->toBase($value, $currency);
            } else {
                $otherAssets += $fx->toBase($value, $currency);
            }
        }
        $investments = Investment::ownedBy($owner)->get()->filter->isZakatable()
            ->sum(fn (Investment $i) => $fx->toBase($i->current_value, $i->currency));

        $debts = Debt::ownedBy($owner)->where('status', DebtStatus::Open)->with('payments')->get();
        $owed = $debts->where('direction', DebtDirection::OwedToMe)->sum(fn (Debt $d) => $fx->toBase($d->remaining(), $d->currency));
        $owe = $debts->where('direction', DebtDirection::IOwe)->sum(fn (Debt $d) => $fx->toBase($d->remaining(), $d->currency));

        // Gameya: money paid in beyond what you took out is owed back to you; the reverse is a debt.
        $gameya = Gameya::ownedBy($owner)->with('payments')->get()->sum(fn (Gameya $g) => $fx->toBase($g->position(), $g->currency));
        $owed += max(0, $gameya);
        $owe += max(0, -$gameya);
        // Installments: only those falling due within the coming year reduce this year's zakat.
        $yearAhead = $today->addYear();
        $installments = InstallmentPlan::ownedBy($owner)->withCount('payments')->get()
            ->sum(fn (InstallmentPlan $p) => $fx->toBase($p->remainingUntil($yearAhead), $p->currency));

        $gross = $cash + $metals + $otherAssets + $investments + $owed;
        $deductions = $owner->zakat_deduct_debts ? $owe + $overdrawn + $installments : 0.0;
        $net = max(0.0, $gross - $deductions);

        $gold = $owner->zakat_nisab === 'gold';
        $price = (float) ($gold ? $owner->gold_price_24k : $owner->silver_price);
        $nisab = $price * ($gold ? self::GOLD_NISAB_GRAMS : self::SILVER_NISAB_GRAMS);
        $rate = $owner->zakat_calendar === 'gregorian' ? self::RATE_GREGORIAN : self::RATE_HIJRI;
        $reached = $price > 0 && $net >= $nisab;

        $date = $owner->zakat_date ? CarbonImmutable::parse($owner->zakat_date) : null;

        return [
            'base_currency' => $fx->base,
            'settings' => [
                'nisab' => $owner->zakat_nisab, 'calendar' => $owner->zakat_calendar,
                'date' => $date?->toDateString(), 'deduct_debts' => $owner->zakat_deduct_debts,
            ],
            'parts' => [
                'cash' => $r($cash), 'metals' => $r($metals), 'other_assets' => $r($otherAssets),
                'investments' => $r($investments), 'owed_to_me' => $r($owed),
                'i_owe' => $r($owner->zakat_deduct_debts ? $owe : 0), 'overdrawn' => $r($owner->zakat_deduct_debts ? $overdrawn : 0),
                'installments' => $r($owner->zakat_deduct_debts ? $installments : 0),
            ],
            'gross' => $r($gross), 'deductions' => $r($deductions), 'net' => $r($net),
            'nisab' => $r($nisab), 'price_missing' => $price <= 0, 'reached' => $reached,
            'rate' => $rate, 'due' => $reached ? $r($net * $rate) : 0.0,
            'days_left' => $date ? (int) $today->diffInDays($date, false) : null,
        ];
    }

    /** Record a zakat payment as an expense; when complete, move the zakat day to next year. */
    public function pay(User $owner, array $data): ZakatPayment
    {
        return DB::transaction(function () use ($owner, $data) {
            $calc = $this->compute($owner);
            $account = Account::findOrFail($data['account_id']);
            $date = $data['date'] ?? CarbonImmutable::today()->toDateString();
            $amount = (float) ($data['amount'] ?? $calc['due']);

            $payment = $owner->zakatPayments()->create([
                'amount' => $amount, 'date' => $date, 'zakatable_wealth' => $calc['net'],
                'account_id' => $account->id, 'note' => $data['note'] ?? null,
            ]);
            $this->tx->create($owner, [
                'type' => TransactionType::Expense->value, 'account_id' => $account->id,
                'category_id' => DefaultCategories::ensure($owner, 'zakat'),
                'amount' => CurrencyConverter::for($owner)->convert($amount, $owner->base_currency, $account->currency),
                'date' => $date, 'note' => $data['note'] ?? null,
                'source_type' => $payment->getMorphClass(), 'source_id' => $payment->id,
            ]);

            // "This year's zakat is complete" (default): move the zakat day to the next hawl.
            // Partial payments leave it in place.
            if ($data['complete'] ?? true) {
                $paidOn = CarbonImmutable::parse($date);
                $next = $owner->zakat_date ? CarbonImmutable::parse($owner->zakat_date) : $paidOn;
                do {
                    $next = $next->add($this->period($owner));
                } while ($next->lte($paidOn));
                $owner->zakat_date = $next->toDateString();
                $owner->save();
            }

            return $payment;
        });
    }

    public function deletePayment(ZakatPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            if ($tx = $payment->transaction) {
                $this->tx->delete($tx);
            }
            $payment->delete();
        });
    }

    /** A lunar year is 354 days; a solar year is a calendar year. */
    private function period(User $owner): \DateInterval
    {
        return $owner->zakat_calendar === 'gregorian' ? new \DateInterval('P1Y') : new \DateInterval('P354D');
    }
}
