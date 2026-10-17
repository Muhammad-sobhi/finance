<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gameya;
use App\Models\GameyaMember;
use App\Models\GameyaPayment;
use App\Services\CurrencyConverter;
use App\Services\GameyaManager;
use App\Services\InstallmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GameyaController extends Controller
{
    public function __construct(private readonly InstallmentService $service, private readonly GameyaManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $fx = CurrencyConverter::for($request->user());
        $items = Gameya::ownedBy($request->user())->with('payments')->get();
        $rows = $items->map(fn (Gameya $g) => $this->present($g))->sortBy([['done', 'asc'], ['next_date', 'asc']])->values();

        return response()->json(['data' => $rows, 'meta' => [
            'base_currency' => $fx->base,
            'position' => round($items->sum(fn ($g) => $fx->toBase($g->position(), $g->currency)), 2),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Gameya::class);
        $g = DB::transaction(function () use ($request) {
            $g = $request->user()->gameyas()->create($this->validated($request, null));
            if ($g->isManaged()) {
                $this->manager->syncSlots($g);
            }

            return $g;
        });

        return response()->json($this->present($g->load('payments')), 201);
    }

    public function show(Gameya $gameya): JsonResponse
    {
        Gate::authorize('view', $gameya);
        $gameya->load('payments');
        $slots = $gameya->isManaged() ? ['slots' => $this->slots($gameya)] : [];

        return response()->json($this->present($gameya) + $slots + [
            'payments' => $gameya->payments->map(fn (GameyaPayment $p) => [
                'id' => $p->id, 'kind' => $p->kind, 'round' => $p->round, 'amount' => (float) $p->amount,
                'date' => $p->date->format('Y-m-d'), 'account_id' => $p->account_id,
            ])->sortByDesc('date')->values(),
        ]);
    }

    public function update(Request $request, Gameya $gameya): JsonResponse
    {
        Gate::authorize('update', $gameya);
        DB::transaction(function () use ($request, $gameya) {
            $gameya->update($this->validated($request, $gameya->load('payments')));
            if ($gameya->isManaged()) {
                $this->manager->syncSlots($gameya);
            }
        });

        return response()->json($this->present($gameya->fresh('payments')));
    }

    public function destroy(Gameya $gameya): JsonResponse
    {
        Gate::authorize('delete', $gameya);
        $this->service->deleteGameya($gameya);

        return response()->json(null, 204);
    }

    public function pay(Request $request, Gameya $gameya): JsonResponse
    {
        return $this->move($request, $gameya, 'contribution');
    }

    public function receive(Request $request, Gameya $gameya): JsonResponse
    {
        return $this->move($request, $gameya, 'payout');
    }

    public function deletePayment(Gameya $gameya, GameyaPayment $payment): JsonResponse
    {
        Gate::authorize('update', $gameya);
        abort_unless($payment->gameya_id === $gameya->id, 404);
        $this->service->deleteGameyaPayment($payment);

        return response()->json($this->present($gameya->fresh('payments')));
    }

    private function move(Request $request, Gameya $gameya, string $kind): JsonResponse
    {
        Gate::authorize('update', $gameya);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
        ]);
        $this->service->gameyaMove($gameya, $kind, $data);

        return response()->json($this->present($gameya->fresh('payments')), 201);
    }

    private function validated(Request $request, ?Gameya $g): array
    {
        $req = $g ? 'sometimes' : 'required';
        $members = (int) $request->input('members', $g?->members ?? 0);
        // The role is set once: "member" (my own share) or "manager" (I run it; my turn is optional).
        $managed = $g ? $g->isManaged() : $request->input('role') === 'manager';
        $data = $request->validate([
            'role' => [$g ? 'prohibited' : 'sometimes', Rule::in(['member', 'manager'])],
            'name' => [$req, 'string', 'max:120'],
            'organizer' => ['nullable', 'string', 'max:120'],
            'members' => [$req, 'integer', 'min:'.max(2, $g?->paidRounds() ?? 2), 'max:100'],
            'my_turn' => [$managed ? 'nullable' : $req, 'integer', 'min:1', 'max:'.max(1, $members)],
            'amount' => [$req, 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'start_date' => [$req, 'date_format:Y-m-d'],
            'cycle' => ['sometimes', Rule::in(['weekly', 'monthly'])],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'remind_days' => ['nullable', 'integer', 'between:0,60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if ($g && $g->payments->isNotEmpty() && array_key_exists('my_turn', $data) && ($data['my_turn'] === null) !== ($g->my_turn === null)) {
            abort(response()->json(['message' => 'You already paid into this gameya.', 'errors' => ['my_turn' => ['You already paid into this gameya.']]], 422));
        }
        if ($g?->received() && isset($data['my_turn']) && $data['my_turn'] !== $g->my_turn) {
            abort(response()->json(['message' => 'The payout was already received.', 'errors' => ['my_turn' => ['The payout was already received.']]], 422));
        }

        return $data;
    }

    private function present(Gameya $g): array
    {
        $today = CarbonImmutable::today();
        $next = $g->nextRound();
        $nextDate = $next ? $g->roundDate($next) : null;
        $payoutDate = $g->hasMyTurn() ? $g->roundDate($g->my_turn) : null;
        $managed = [];
        if ($g->isManaged()) {
            $slots = $g->slots()->with('payments')->get();
            $round = $g->currentRound($today);
            $managed = ['current_round' => $round, 'current_date' => $g->roundDate($round)->toDateString(),
                'paid_this_round' => $slots->filter(fn ($m) => $m->is_me ? $g->paidRounds() >= $round : in_array($round, $m->paidRounds(true), true))->count(),
                'claims' => $slots->sum(fn ($m) => $m->payments->where('confirmed', false)->count()),
                'linked' => $slots->filter->isLinked()->count(),
                'round_dates' => array_map(fn ($r) => $g->roundDate($r)->toDateString(), range(1, $g->members))];
        }

        return $managed + [
            'id' => $g->id, 'name' => $g->name, 'organizer' => $g->organizer, 'role' => $g->role, 'members' => $g->members, 'my_turn' => $g->my_turn,
            'amount' => (float) $g->amount, 'currency' => $g->currency, 'start_date' => $g->start_date->format('Y-m-d'),
            'cycle' => $g->cycle->value, 'account_id' => $g->account_id, 'remind_days' => $g->remind_days, 'notes' => $g->notes,
            'payout' => $g->payout(), 'paid_rounds' => $g->paidRounds(), 'received' => $g->received(), 'position' => $g->position(),
            'next_round' => $next, 'next_date' => $nextDate?->toDateString(), 'days' => $nextDate ? (int) $today->diffInDays($nextDate, false) : null,
            'payout_date' => $payoutDate?->toDateString(), 'payout_days' => $g->received() || ! $payoutDate ? null : (int) $today->diffInDays($payoutDate, false),
            'end_date' => $g->roundDate($g->members)->toDateString(), 'done' => $g->isDone(),
        ];
    }

    /** Every turn as the manager sees it. */
    private function slots(Gameya $g): array
    {
        return $g->slots()->with('payments')->get()->map(fn (GameyaMember $m) => array_merge([
            'id' => $m->id, 'turn' => $m->turn, 'is_me' => $m->is_me, 'name' => $m->name, 'phone' => $m->phone, 'email' => $m->email, 'notes' => $m->notes,
            'link' => $m->linkStatus(), 'payout_date' => $m->payout_date?->format('Y-m-d'), 'payout_amount' => $m->payout_amount !== null ? (float) $m->payout_amount : null,
            'turn_date' => $g->roundDate($m->turn)->toDateString(),
            // The owner's own turn follows their own pay/receive records.
            'payments' => $m->is_me
                ? $g->payments->where('kind', 'contribution')->map(fn ($p) => ['id' => null, 'round' => $p->round, 'date' => $p->date->format('Y-m-d'), 'amount' => (float) $p->amount, 'confirmed' => true])->values()
                : $m->payments->map(fn ($p) => ['id' => $p->id, 'round' => $p->round, 'date' => $p->date->format('Y-m-d'), 'amount' => (float) $p->amount, 'confirmed' => $p->confirmed])->values(),
        ], $m->is_me ? ['payout_date' => $g->payments->firstWhere('kind', 'payout')?->date->format('Y-m-d')] : []))->all();
    }
}
