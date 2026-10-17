<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gameya;
use App\Models\GameyaPayment;
use App\Services\CurrencyConverter;
use App\Services\InstallmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GameyaController extends Controller
{
    public function __construct(private readonly InstallmentService $service) {}

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
        $g = $request->user()->gameyas()->create($this->validated($request, null));

        return response()->json($this->present($g->load('payments')), 201);
    }

    public function show(Gameya $gameya): JsonResponse
    {
        Gate::authorize('view', $gameya);
        $gameya->load('payments');

        return response()->json($this->present($gameya) + [
            'payments' => $gameya->payments->map(fn (GameyaPayment $p) => [
                'id' => $p->id, 'kind' => $p->kind, 'round' => $p->round, 'amount' => (float) $p->amount,
                'date' => $p->date->format('Y-m-d'), 'account_id' => $p->account_id,
            ])->sortByDesc('date')->values(),
        ]);
    }

    public function update(Request $request, Gameya $gameya): JsonResponse
    {
        Gate::authorize('update', $gameya);
        $gameya->update($this->validated($request, $gameya->load('payments')));

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
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'organizer' => ['nullable', 'string', 'max:120'],
            'members' => [$req, 'integer', 'min:'.max(2, $g?->paidRounds() ?? 2), 'max:100'],
            'my_turn' => [$req, 'integer', 'min:1', 'max:'.max(1, $members)],
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
        $payoutDate = $g->roundDate($g->my_turn);

        return [
            'id' => $g->id, 'name' => $g->name, 'organizer' => $g->organizer, 'members' => $g->members, 'my_turn' => $g->my_turn,
            'amount' => (float) $g->amount, 'currency' => $g->currency, 'start_date' => $g->start_date->format('Y-m-d'),
            'cycle' => $g->cycle->value, 'account_id' => $g->account_id, 'remind_days' => $g->remind_days, 'notes' => $g->notes,
            'payout' => $g->payout(), 'paid_rounds' => $g->paidRounds(), 'received' => $g->received(), 'position' => $g->position(),
            'next_round' => $next, 'next_date' => $nextDate?->toDateString(), 'days' => $nextDate ? (int) $today->diffInDays($nextDate, false) : null,
            'payout_date' => $payoutDate->toDateString(), 'payout_days' => $g->received() ? null : (int) $today->diffInDays($payoutDate, false),
            'end_date' => $g->roundDate($g->members)->toDateString(), 'done' => $g->isDone(),
        ];
    }
}
