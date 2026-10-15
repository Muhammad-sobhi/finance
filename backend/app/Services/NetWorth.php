<?php

namespace App\Services;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Debt;
use App\Models\Gameya;
use App\Models\InstallmentPlan;
use App\Models\Investment;
use App\Models\NetWorthSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;

/** The single definition of net worth: accounts + investments + assets + owed to me + gameya position − I owe − installments left. */
class NetWorth
{
    /** @return array{accounts: float, liquid: float, investments: float, assets: float, owed_to_me: float, i_owe: float, installments: float, gameya: float, net_worth: float} */
    public function compute(User $user): array
    {
        $owner = $user->dataOwner();
        $fx = CurrencyConverter::for($owner);

        $accounts = Account::ownedBy($owner)->where('is_active', true)->get();
        $accTotal = $accounts->sum(fn (Account $a) => $fx->toBase($a->balance, $a->currency));
        $liquid = $accounts->filter(fn (Account $a) => $a->type->isLiquid() && $a->balance > 0)->sum(fn (Account $a) => $fx->toBase($a->balance, $a->currency));

        $debts = Debt::ownedBy($owner)->where('status', DebtStatus::Open)->withSum('payments', 'amount')->get();
        $owed = $debts->where('direction', DebtDirection::OwedToMe)->sum(fn (Debt $d) => $fx->toBase($d->remaining(), $d->currency));
        $owe = $debts->where('direction', DebtDirection::IOwe)->sum(fn (Debt $d) => $fx->toBase($d->remaining(), $d->currency));

        $investments = Investment::ownedBy($owner)->get()->sum(fn (Investment $i) => $fx->toBase($i->current_value, $i->currency));
        $assets = Asset::ownedBy($owner)->get()->sum(function (Asset $a) use ($owner, $fx) {
            [$value, $currency] = $a->valuation($owner);

            return $fx->toBase($value, $currency);
        });

        $installments = InstallmentPlan::ownedBy($owner)->withCount('payments')->get()->sum(fn (InstallmentPlan $p) => $fx->toBase($p->remaining(), $p->currency));
        $gameya = Gameya::ownedBy($owner)->with('payments')->get()->sum(fn (Gameya $g) => $fx->toBase($g->position(), $g->currency));

        $r = fn ($v) => round((float) $v, 2);

        return [
            'accounts' => $r($accTotal), 'liquid' => $r($liquid), 'investments' => $r($investments), 'assets' => $r($assets),
            'owed_to_me' => $r($owed), 'i_owe' => $r($owe), 'installments' => $r($installments), 'gameya' => $r($gameya),
            'net_worth' => $r($accTotal + $investments + $assets + $owed + $gameya - $owe - $installments),
        ];
    }

    /** Upsert this month's snapshot (run daily; the last run of the month is the month-end value). */
    public function snapshot(User $owner, ?CarbonImmutable $today = null): NetWorthSnapshot
    {
        $month = ($today ?? CarbonImmutable::today())->startOfMonth()->toDateString();
        $data = $this->compute($owner);

        return $owner->snapshots()->updateOrCreate(
            ['month' => $month],
            ['net_worth' => $data['net_worth'], 'breakdown' => $data],
        );
    }
}
