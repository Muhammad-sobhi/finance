<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Http\Controllers\Controller;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Services\CurrencyConverter;
use App\Services\DebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DebtController extends Controller
{
    public function __construct(private readonly DebtService $service) {}

    /** Open first, then by due date. Meta carries totals still owed each way, in base currency. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::enum(DebtStatus::class)], 'direction' => ['nullable', Rule::enum(DebtDirection::class)]]);
        $fx = CurrencyConverter::for($request->user());
        $debts = Debt::ownedBy($request->user())->with('payments')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('date')
            ->get();

        $totals = ['owed_to_me' => 0.0, 'i_owe' => 0.0];
        foreach ($debts->where('status', DebtStatus::Open) as $d) {
            $totals[$d->direction->value] = round($totals[$d->direction->value] + $fx->toBase($d->remaining(), $d->currency), 2);
        }

        return response()->json(['data' => $debts->map(fn (Debt $d) => $this->present($d)), 'meta' => ['totals' => $totals, 'base_currency' => $fx->base]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Debt::class);
        $debt = $this->service->create($request->user(), $this->validated($request, true));

        return response()->json($this->present($debt->fresh()), 201);
    }

    public function show(Debt $debt): JsonResponse
    {
        Gate::authorize('view', $debt);

        return response()->json($this->present($debt) + [
            'payments' => $debt->payments->map(fn (DebtPayment $p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'date' => $p->date->format('Y-m-d'),
                'account_id' => $p->account_id, 'note' => $p->note,
            ]),
        ]);
    }

    public function update(Request $request, Debt $debt): JsonResponse
    {
        Gate::authorize('update', $debt);
        $data = $this->validated($request, false);
        if (isset($data['amount']) && (float) $data['amount'] < $debt->paid()) {
            return response()->json(['message' => 'Amount is below what was already paid.', 'errors' => ['amount' => ['Amount is below what was already paid.']]], 422);
        }

        return response()->json($this->present($this->service->update($debt, $data)->fresh()));
    }

    public function destroy(Debt $debt): JsonResponse
    {
        Gate::authorize('delete', $debt);
        $this->service->delete($debt);

        return response()->json(null, 204);
    }

    public function addPayment(Request $request, Debt $debt): JsonResponse
    {
        Gate::authorize('update', $debt);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.($debt->remaining() + 0.001)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $data['date'] ??= now()->toDateString();
        $payment = $this->service->addPayment($debt, $data);

        return response()->json(['id' => $payment->id, 'debt' => $this->present($debt->fresh())], 201);
    }

    public function deletePayment(Debt $debt, DebtPayment $payment): JsonResponse
    {
        Gate::authorize('update', $debt);
        abort_unless($payment->debt_id === $debt->id, 404);
        $this->service->deletePayment($payment);

        return response()->json($this->present($debt->fresh()));
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'person' => [$req, 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'direction' => [$req, Rule::enum(DebtDirection::class)],
            'amount' => [$req, 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::enum(DebtStatus::class)],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'remind_days' => ['nullable', 'integer', 'between:0,365'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if ($create) {
            $data['date'] ??= now()->toDateString();
        }

        return $data;
    }

    private function present(Debt $d): array
    {
        return [
            'id' => $d->id, 'person' => $d->person, 'phone' => $d->phone, 'direction' => $d->direction->value,
            'amount' => (float) $d->amount, 'currency' => $d->currency, 'date' => $d->date->format('Y-m-d'),
            'due_date' => $d->due_date?->format('Y-m-d'), 'notes' => $d->notes, 'status' => $d->status->value,
            'account_id' => $d->account_id, 'remind_days' => $d->remind_days,
            'paid' => $d->paid(), 'remaining' => $d->remaining(),
        ];
    }
}
