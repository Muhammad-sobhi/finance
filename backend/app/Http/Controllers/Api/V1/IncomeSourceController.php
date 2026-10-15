<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\IncomeSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recurring income (salary etc.). "Received" = POST /income-sources/{id}/receive. */
class IncomeSourceController extends RecurringController
{
    protected function model(): string
    {
        return IncomeSource::class;
    }

    protected function extraRules(bool $create): array
    {
        return ['next_date' => [$create ? 'required' : 'sometimes', 'date_format:Y-m-d']];
    }

    /** Meta: expected income per month across active sources, in base currency. */
    public function index(Request $request): JsonResponse
    {
        $fx = $this->base($request);
        $items = $this->query($request)->get();
        $monthly = $items->where('is_active', true)->sum(fn (IncomeSource $s) => $fx->toBase($s->monthlyCost(), $s->currency));

        return response()->json([
            'data' => $items->map(fn (IncomeSource $s) => $this->present($s) + ['monthly' => round($s->monthlyCost(), 2)]),
            'meta' => ['monthly' => round($monthly, 2), 'base_currency' => $fx->base],
        ]);
    }
}
