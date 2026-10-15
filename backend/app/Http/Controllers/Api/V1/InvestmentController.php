<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvestmentType;
use App\Enums\PayoutCycle;
use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentValue;
use App\Services\CurrencyConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvestmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fx = CurrencyConverter::for($request->user());
        $items = Investment::ownedBy($request->user())->orderByDesc('current_value')->get();
        $invested = $items->sum(fn (Investment $i) => $fx->toBase($i->invested_amount, $i->currency));
        $current = $items->sum(fn (Investment $i) => $fx->toBase($i->current_value, $i->currency));

        return response()->json([
            'data' => $items->map(fn (Investment $i) => $this->present($i, $fx)),
            'meta' => [
                'base_currency' => $fx->base,
                'invested' => round($invested, 2),
                'current' => round($current, 2),
                'profit' => round($current - $invested, 2),
                'roi' => $invested > 0 ? round(($current - $invested) / $invested * 100, 2) : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Investment::class);
        $inv = $request->user()->investments()->create($this->validated($request, true));

        return response()->json($this->present($inv->fresh(), CurrencyConverter::for($request->user())), 201);
    }

    /** Detail includes the value history for growth charts. */
    public function show(Request $request, Investment $investment): JsonResponse
    {
        Gate::authorize('view', $investment);

        return response()->json($this->present($investment, CurrencyConverter::for($request->user())) + [
            'values' => $investment->values->map(fn (InvestmentValue $v) => ['id' => $v->id, 'date' => $v->date->format('Y-m-d'), 'value' => (float) $v->value]),
        ]);
    }

    public function update(Request $request, Investment $investment): JsonResponse
    {
        Gate::authorize('update', $investment);
        $investment->update($this->validated($request, false));

        return response()->json($this->present($investment->fresh(), CurrencyConverter::for($request->user())));
    }

    public function destroy(Investment $investment): JsonResponse
    {
        Gate::authorize('delete', $investment);
        $investment->delete();

        return response()->json(null, 204);
    }

    /** Record a dated valuation; the latest one becomes the current value. */
    public function addValue(Request $request, Investment $investment): JsonResponse
    {
        Gate::authorize('update', $investment);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ]);
        $investment->values()->updateOrCreate(['date' => $data['date']], ['value' => $data['value']]);
        $latest = $investment->values()->reorder()->orderByDesc('date')->first();
        $investment->current_value = $latest->value;
        $investment->saveQuietly(); // no extra "today" snapshot for a back-dated value

        return $this->show($request, $investment->fresh());
    }

    public function deleteValue(Request $request, Investment $investment, InvestmentValue $value): JsonResponse
    {
        Gate::authorize('update', $investment);
        abort_unless($value->investment_id === $investment->id, 404);
        abort_if($investment->values()->count() <= 1, 422, 'Keep at least one value.');
        $value->delete();
        $investment->current_value = $investment->values()->reorder()->orderByDesc('date')->value('value');
        $investment->saveQuietly();

        return $this->show($request, $investment->fresh());
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'type' => [$req, Rule::enum(InvestmentType::class)],
            'platform' => ['nullable', 'string', 'max:120'],
            'invested_amount' => [$req, 'numeric', 'min:0', 'max:9999999999999'],
            'current_value' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:24'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'maturity_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'annual_return' => ['nullable', 'numeric', 'between:-100,1000'],
            'payout_cycle' => ['nullable', Rule::enum(PayoutCycle::class)],
            'remind_days' => ['nullable', 'integer', 'between:0,365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'zakatable' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if ($create) {
            $data['current_value'] ??= $data['invested_amount'];
        }

        return $data;
    }

    private function present(Investment $i, CurrencyConverter $fx): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'type' => $i->type->value, 'platform' => $i->platform,
            'invested_amount' => (float) $i->invested_amount, 'current_value' => (float) $i->current_value,
            'currency' => $i->currency, 'base_value' => $fx->toBase($i->current_value, $i->currency),
            'quantity' => $i->quantity !== null ? (float) $i->quantity : null, 'unit' => $i->unit,
            'start_date' => $i->start_date?->format('Y-m-d'), 'maturity_date' => $i->maturity_date?->format('Y-m-d'),
            'annual_return' => $i->annual_return !== null ? (float) $i->annual_return : null,
            'payout_cycle' => $i->payout_cycle?->value, 'remind_days' => $i->remind_days, 'notes' => $i->notes,
            'zakatable' => $i->isZakatable(),
            'profit' => $i->profit(), 'roi' => $i->roi(),
        ];
    }
}
