<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GameyaMember;
use App\Services\GameyaManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A member's view of gameyas someone else runs and linked them to. They see their own
 * turn(s), payout date and their own payments only — never the other members.
 */
class SharedGameyaController extends Controller
{
    public function __construct(private readonly GameyaManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->dataOwner();
        $slots = GameyaMember::where('linked_user_id', $user->id)->with(['gameya.user', 'payments'])->get();
        $byGameya = $slots->groupBy('gameya_id');

        return response()->json([
            'invites' => $slots->whereNull('accepted_at')->map(fn (GameyaMember $m) => [
                'id' => $m->id, 'gameya' => $m->gameya->name, 'manager' => $m->gameya->user->name, 'turn' => $m->turn,
                'amount' => (float) $m->gameya->amount, 'currency' => $m->gameya->currency,
            ])->values(),
            'data' => $byGameya->map(fn (Collection $mine) => $this->present($mine->whereNotNull('accepted_at')->values()))->filter()->values(),
        ]);
    }

    public function accept(Request $request, GameyaMember $member): JsonResponse
    {
        $this->mine($request, $member);
        $this->manager->accept($member);

        return $this->index($request);
    }

    public function decline(Request $request, GameyaMember $member): JsonResponse
    {
        $this->mine($request, $member);
        $this->manager->decline($member);

        return $this->index($request);
    }

    public function claim(Request $request, GameyaMember $member): JsonResponse
    {
        $this->mine($request, $member, accepted: true);
        $this->manager->claim($member, (int) $request->validate(['round' => ['required', 'integer', 'min:1']])['round']);

        return $this->index($request);
    }

    private function mine(Request $request, GameyaMember $member, bool $accepted = false): void
    {
        $user = $request->user();
        abort_unless($user->isOwner() && $member->linked_user_id === $user->id && (! $accepted || $member->accepted_at), 404);
    }

    /** One gameya from the member's side: their turns and their payments only. */
    private function present(Collection $mine): ?array
    {
        if ($mine->isEmpty()) {
            return null;
        }
        $g = $mine->first()->gameya;
        $today = CarbonImmutable::today();
        $paid = $mine->flatMap(fn (GameyaMember $m) => $m->payments->map(fn ($p) => [
            'turn' => $m->turn, 'round' => $p->round, 'date' => $p->date->format('Y-m-d'), 'amount' => (float) $p->amount, 'confirmed' => $p->confirmed,
        ]))->sortBy('round')->values();
        // Each turn pays every round; the next unpaid round of any of my turns is what I owe next.
        $next = null;
        foreach ($mine as $m) {
            $rounds = $m->paidRounds();
            for ($r = 1; $r <= $g->members; $r++) {
                if (! in_array($r, $rounds, true)) {
                    if ($next === null || $r < $next['round']) {
                        $next = ['member_id' => $m->id, 'round' => $r, 'date' => $g->roundDate($r)->toDateString(), 'days' => (int) $today->diffInDays($g->roundDate($r), false)];
                    }
                    break;
                }
            }
        }

        return [
            'gameya' => $g->name, 'manager' => $g->user->name, 'amount' => (float) $g->amount, 'currency' => $g->currency,
            'cycle' => $g->cycle->value, 'members' => $g->members, 'start_date' => $g->start_date->format('Y-m-d'),
            'end_date' => $g->roundDate($g->members)->toDateString(),
            'turns' => $mine->map(fn (GameyaMember $m) => [
                'member_id' => $m->id, 'turn' => $m->turn, 'payout' => $g->payout(), 'payout_date' => $g->roundDate($m->turn)->toDateString(),
                'received' => $m->payout_date !== null, 'received_date' => $m->payout_date?->format('Y-m-d'),
            ])->values(),
            'paid' => $paid, 'paid_count' => $paid->where('confirmed', true)->count(), 'rounds_total' => $g->members * $mine->count(),
            'next' => $next,
        ];
    }
}
