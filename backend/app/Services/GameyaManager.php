<?php

namespace App\Services;

use App\Models\Gameya;
use App\Models\GameyaMember;
use App\Models\GameyaMemberPayment;
use App\Models\User;
use App\Notifications\InfoAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Running a gameya: one slot per turn, each round's share per member, payouts handed over,
 * and the link between a turn and a member's own account (invite → accept). The owner's own
 * turn (is_me) keeps using the money-moving pay/receive flow of InstallmentService.
 */
class GameyaManager
{
    /** One slot per turn; shrinking only drops empty trailing turns. */
    public function syncSlots(Gameya $g): void
    {
        DB::transaction(function () use ($g) {
            $slots = $g->slots()->with('payments')->get()->keyBy('turn');
            foreach ($slots as $turn => $slot) {
                if ($turn > $g->members) {
                    if ($slot->payments->isNotEmpty() || $slot->payout_date || $slot->linked_user_id || $slot->name) {
                        throw ValidationException::withMessages(['members' => 'Turn '.$turn.' has a member or payments; clear it first.']);
                    }
                    $slot->delete();
                }
            }
            for ($turn = 1; $turn <= $g->members; $turn++) {
                $slot = $slots[$turn] ?? $g->slots()->make(['turn' => $turn]);
                $slot->is_me = $g->my_turn === $turn;
                $slot->save();
            }
        });
    }

    public function markPaid(GameyaMember $m, array $data): GameyaMemberPayment
    {
        $g = $m->gameya;
        $this->notMine($m);
        $round = (int) $data['round'];
        $round <= $g->members || throw ValidationException::withMessages(['round' => 'No such round.']);

        return DB::transaction(function () use ($m, $g, $round, $data) {
            $p = $m->payments()->firstOrNew(['round' => $round], ['gameya_id' => $g->id]);
            $p->fill([
                'amount' => $data['amount'] ?? $g->amount,
                'date' => $data['date'] ?? CarbonImmutable::today()->toDateString(),
                'confirmed' => true,
            ]);
            $p->gameya_id = $g->id;
            $p->save();

            return $p;
        });
    }

    public function payout(GameyaMember $m, array $data): void
    {
        $this->notMine($m);
        $m->forceFill([
            'payout_date' => $data['date'] ?? CarbonImmutable::today()->toDateString(),
            'payout_amount' => $data['amount'] ?? $m->gameya->payout(),
        ])->save();
    }

    /** Invite the holder of a turn by email. The manager learns nothing about whether the email has an account. */
    public function invite(GameyaMember $m, User $manager): void
    {
        $this->notMine($m);
        $m->email || throw ValidationException::withMessages(['email' => 'Add the member\'s email first.']);
        $user = User::where('email', strtolower(trim($m->email)))->first();
        $eligible = $user && $user->isOwner() && $user->id !== $manager->id;
        $m->forceFill(['invited_at' => now(), 'accepted_at' => null, 'linked_user_id' => $eligible ? $user->id : null])->save();
        if ($eligible) {
            $user->notify(new InfoAlert('gameya_invite', ['manager' => $manager->name, 'gameya' => $m->gameya->name], '/debts?tab=gameyas'));
        }
    }

    public function unlink(GameyaMember $m): void
    {
        $m->forceFill(['linked_user_id' => null, 'invited_at' => null, 'accepted_at' => null])->save();
    }

    /** Remind a linked member about the current round (if they haven't paid it). */
    public function remind(GameyaMember $m): bool
    {
        $g = $m->gameya;
        $round = $g->currentRound();
        if (! $m->isLinked() || in_array($round, $m->paidRounds(), true)) {
            return false;
        }
        $m->linkedUser->notify(new InfoAlert('gameya_reminder', [
            'gameya' => $g->name, 'round' => $round, 'amount' => (float) $g->amount, 'currency' => $g->currency,
            'date' => $g->roundDate($round)->toDateString(),
        ], '/debts?tab=gameyas'));

        return true;
    }

    // ---- The member's side ----

    public function accept(GameyaMember $m): void
    {
        $m->forceFill(['accepted_at' => now()])->save();
    }

    public function decline(GameyaMember $m): void
    {
        $this->unlink($m);
    }

    /** "I paid round N": recorded unconfirmed; the manager is told and confirms. */
    public function claim(GameyaMember $m, int $round): void
    {
        $g = $m->gameya;
        ($round >= 1 && $round <= $g->members) || throw ValidationException::withMessages(['round' => 'No such round.']);
        $m->payments()->where('round', $round)->exists() && throw ValidationException::withMessages(['round' => 'This round is already recorded.']);
        $p = new GameyaMemberPayment(['round' => $round, 'amount' => $g->amount, 'date' => CarbonImmutable::today()->toDateString(), 'confirmed' => false]);
        $p->gameya_id = $g->id;
        $p->member_id = $m->id;
        $p->save();
        $g->user->notify(new InfoAlert('gameya_claim', ['member' => $m->name ?: (string) $m->turn, 'gameya' => $g->name, 'round' => $round], '/debts?tab=gameyas'));
    }

    private function notMine(GameyaMember $m): void
    {
        $m->is_me && throw ValidationException::withMessages(['member' => 'Use pay / received for your own turn.']);
    }
}
