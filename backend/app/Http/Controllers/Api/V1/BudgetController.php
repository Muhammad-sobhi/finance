<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\BudgetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $service) {}

    /** Every budget with this month's (or ?month=YYYY-MM) spending, most-used first. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->filled('month') ? CarbonImmutable::createFromFormat('!Y-m', $request->input('month')) : CarbonImmutable::today();
        $rows = collect($this->service->status($request->user(), $month));

        return response()->json(['data' => $rows, 'meta' => [
            'month' => $month->format('Y-m'), 'base_currency' => $request->user()->dataOwner()->base_currency,
            'budgeted' => round($rows->sum('amount'), 2), 'spent' => round($rows->sum('spent'), 2),
            'remaining' => round($rows->sum('amount') - $rows->sum('spent'), 2),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Budget::class);
        $user = $request->user();
        $data = $request->validate([
            'category_id' => ['required', 'integer',
                Rule::exists('categories', 'id')->where('user_id', $user->id)->where('type', CategoryType::Expense->value),
                Rule::unique('budgets', 'category_id')->where('user_id', $user->id)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
        ]);
        $budget = $user->budgets()->create($data);
        $this->service->watch($user);

        return response()->json(['id' => $budget->id], 201);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        Gate::authorize('update', $budget);
        $budget->update($request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999']]));
        $this->service->watch($request->user());

        return response()->json(['id' => $budget->id]);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        Gate::authorize('delete', $budget);
        $budget->delete();

        return response()->json(null, 204);
    }
}
