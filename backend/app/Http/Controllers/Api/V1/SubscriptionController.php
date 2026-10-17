<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Scope;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends RecurringController
{
    protected function model(): string
    {
        return Subscription::class;
    }

    protected function extraRules(bool $create): array
    {
        return [
            'renew_date' => [$create ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'scope' => [$create ? 'required' : 'sometimes', Rule::enum(Scope::class)],
        ];
    }

    /** Includes the normalized monthly cost of active subscriptions, split personal vs work. */
    public function index(Request $request): JsonResponse
    {
        $fx = $this->base($request);
        $items = $this->items($request);
        $totals = ['personal' => 0.0, 'work' => 0.0];
        foreach ($items->where('is_active', true) as $s) {
            $totals[$s->scope->value] += $fx->toBase($s->monthlyCost(), $s->currency);
        }
        $totals = array_map(fn ($v) => round($v, 2), $totals);

        return response()->json([
            'data' => $items->map(fn (Subscription $s) => $this->present($s)),
            'meta' => ['monthly' => $totals + ['total' => round($totals['personal'] + $totals['work'], 2)], 'base_currency' => $fx->base],
        ]);
    }

    protected function present($i): array
    {
        return parent::present($i) + [
            'scope' => $i->scope->value,
            'start_date' => $i->start_date?->format('Y-m-d'),
            'monthly_cost' => round($i->monthlyCost(), 2),
        ];
    }
}
