<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\DebtStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\GameyaMember;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VaultItem;
use App\Notifications\DueAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds everything due for each owner and sends each item+stage alert exactly once
 * (N days before, on the day, overdue). Safe to run as often as you like.
 */
class AlertScanner
{
    /** @return int number of alerts sent */
    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;
        User::where('role', UserRole::Owner)->each(function (User $owner) use ($today, &$sent) {
            $sent += $this->scanOwner($owner, $today);
        });

        return $sent;
    }

    public function scanOwner(User $owner, CarbonImmutable $today): int
    {
        $sent = app(BudgetService::class)->watch($owner, $today);
        foreach ($this->items($owner, $today) as $item) {
            $stage = $item->stage($today, $owner->alert_days);
            if ($stage === null) {
                continue;
            }
            // The unique index makes this the dedupe: only the first insert wins.
            $fresh = DB::table('alert_logs')->insertOrIgnore([
                'user_id' => $owner->id, 'kind' => $item->kind, 'item_id' => $item->id,
                'stage' => $stage, 'due_date' => $item->due->toDateString(), 'sent_at' => now(),
            ]);
            if ($fresh) {
                $owner->notify(new DueAlert($item, $stage, $item->days($today)));
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, DueItem> */
    public function items(User $owner, CarbonImmutable $today): Collection
    {
        $out = collect();

        foreach ([Bill::class => ['bill', '/bills'], Subscription::class => ['subscription', '/subscriptions']] as $class => [$kind, $path]) {
            $class::where('user_id', $owner->id)->where('is_active', true)->get()->each(fn ($r) => $out->push(new DueItem(
                $owner->id, $kind, $r->id, $r->name, $r->dueDate(), (float) $r->amount, $r->currency, $r->remind_days, $path,
            )));
        }

        // Expected income: no early warning unless the source asks for one; auto-recorded ones need no prompt.
        $owner->incomeSources()->where('is_active', true)->where('auto_pay', false)->get()
            ->each(fn ($r) => $out->push(new DueItem(
                $owner->id, 'income', $r->id, $r->name, $r->dueDate(), (float) $r->amount, $r->currency, $r->remind_days ?? 0, '/income',
            )));
        $owner->projects()->where('status', 'active')->whereNotNull('due_date')->with('payments')->get()
            ->filter(fn ($p) => $p->outstanding() > 0)
            ->each(fn ($p) => $out->push(new DueItem(
                $owner->id, 'project', $p->id, "{$p->client} — {$p->name}", CarbonImmutable::parse($p->due_date), $p->outstanding(), $p->currency, $p->remind_days, '/income',
            )));

        $owner->debts()->where('status', DebtStatus::Open)->whereNotNull('due_date')->with('payments')->get()
            ->each(fn ($d) => $out->push(new DueItem(
                $owner->id, $d->direction->value, $d->id, $d->person, CarbonImmutable::parse($d->due_date), $d->remaining(), $d->currency, $d->remind_days, '/debts',
            )));

        $owner->investments()->whereNotNull('maturity_date')->where('maturity_date', '>=', $today->toDateString())->get()
            ->each(fn ($i) => $out->push(new DueItem(
                $owner->id, 'maturity', $i->id, $i->name, CarbonImmutable::parse($i->maturity_date), (float) $i->current_value, $i->currency, $i->remind_days, '/investments',
            )));

        $owner->accounts()->where('type', AccountType::CreditCard)->where('is_active', true)->whereNotNull('due_day')->get()
            ->filter(fn ($a) => (float) $a->balance < 0)
            ->each(function ($a) use ($out, $today, $owner) {
                $due = $today->setDay(min($a->due_day, $today->daysInMonth));
                if ($due->lt($today)) {
                    $next = $today->startOfMonth()->addMonth();
                    $due = $next->setDay(min($a->due_day, $next->daysInMonth));
                }
                $out->push(new DueItem($owner->id, 'credit_card', $a->id, $a->name, $due, abs((float) $a->balance), $a->currency, null, '/accounts'));
            });

        $owner->installmentPlans()->withCount('payments')->get()
            ->filter(fn ($p) => $p->nextDue() !== null)
            ->each(fn ($p) => $out->push(new DueItem(
                $owner->id, 'installment', $p->id, $p->name, $p->nextDue(), (float) $p->installment_amount, $p->currency, $p->remind_days, '/debts',
            )));
        $owner->gameyas()->with('payments')->get()->filter->hasMyTurn()->each(function ($g) use ($out, $owner) {
            if ($round = $g->nextRound()) {
                $out->push(new DueItem($owner->id, 'gameya', $g->id, $g->name, $g->roundDate($round), (float) $g->amount, $g->currency, $g->remind_days, '/debts'));
            }
            if (! $g->received()) {
                $out->push(new DueItem($owner->id, 'gameya_payout', $g->id, $g->name, $g->roundDate($g->my_turn), $g->payout(), $g->currency, $g->remind_days, '/debts'));
            }
        });

        // Gameyas someone else runs, where this owner holds a turn: their next unpaid round and their payout.
        GameyaMember::where('linked_user_id', $owner->id)->whereNotNull('accepted_at')->with(['gameya', 'payments'])->get()
            ->each(function (GameyaMember $m) use ($out, $owner) {
                $g = $m->gameya;
                $paid = $m->paidRounds();
                $round = collect(range(1, $g->members))->first(fn ($r) => ! in_array($r, $paid, true));
                if ($round) {
                    $out->push(new DueItem($owner->id, 'gameya_shared', $m->id, $g->name, $g->roundDate($round), (float) $g->amount, $g->currency, $g->remind_days, '/debts'));
                }
                if ($m->payout_date === null) {
                    $out->push(new DueItem($owner->id, 'gameya_shared_payout', $m->id, $g->name, $g->roundDate($m->turn), $g->payout(), $g->currency, $g->remind_days, '/debts'));
                }
            });

        // Vault renewals (insurance, ID, passport…): the item itself is sealed, so the alert is generic.
        VaultItem::where('user_id', $owner->id)->whereNotNull('remind_on')->get()
            ->each(fn (VaultItem $v) => $out->push(new DueItem($owner->id, 'vault', $v->id, '', CarbonImmutable::parse($v->remind_on), 0, '', null, '/vault')));

        // Zakat day (a week's notice at least, to prepare the money), only when something is due.
        if ($owner->zakat_date) {
            $zakat = app(ZakatService::class)->compute($owner, $today);
            if ($zakat['due'] > 0) {
                $out->push(new DueItem($owner->id, 'zakat', $owner->id, '', CarbonImmutable::parse($owner->zakat_date), $zakat['due'], $owner->base_currency, max(7, $owner->alert_days), '/planning'));
            }
        }

        return $out;
    }
}
