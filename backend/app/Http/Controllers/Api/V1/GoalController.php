<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Services\CurrencyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Savings goals. Progress is either a linked account's balance (money really set aside)
 * or the sum of contributions recorded by hand.
 */
class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fx = CurrencyConverter::for($request->user());
        $goals = Goal::ownedBy($request->user())->with('account')->withSum('contributions', 'amount')->get()
            ->map(fn (Goal $g) => $this->present($g, $fx))
            ->sortBy([['achieved', 'asc'], ['target_date', 'asc']])->values();

        return response()->json(['data' => $goals, 'meta' => ['base_currency' => $fx->base]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Goal::class);
        $goal = $request->user()->goals()->create($this->validated($request, true));

        return response()->json($this->fresh($goal, $request), 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        Gate::authorize('view', $goal);

        return response()->json($this->fresh($goal, $request) + [
            'contributions' => $goal->contributions()->limit(50)->get()->map(fn (GoalContribution $c) => [
                'id' => $c->id, 'amount' => (float) $c->amount, 'date' => $c->date->format('Y-m-d'), 'note' => $c->note,
            ]),
        ]);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        Gate::authorize('update', $goal);
        $goal->update($this->validated($request, false));

        return response()->json($this->fresh($goal, $request));
    }

    public function destroy(Goal $goal): JsonResponse
    {
        Gate::authorize('delete', $goal);
        $goal->delete();

        return response()->json(null, 204);
    }

    public function addContribution(Request $request, Goal $goal): JsonResponse
    {
        Gate::authorize('update', $goal);
        abort_if($goal->account_id !== null, 409, 'This goal follows an account balance.');
        $saved = (float) $goal->contributions()->sum('amount');
        $data = $request->validate([
            // Negative = taking money back out; never below zero saved.
            'amount' => ['required', 'numeric', 'not_in:0', 'min:'.(-$saved), 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $data['date'] ??= now()->toDateString();
        $c = $goal->contributions()->create($data);

        return response()->json(['id' => $c->id, 'goal' => $this->fresh($goal, $request)], 201);
    }

    public function deleteContribution(Request $request, Goal $goal, GoalContribution $contribution): JsonResponse
    {
        Gate::authorize('update', $goal);
        abort_unless($contribution->goal_id === $goal->id, 404);
        $contribution->delete();

        return response()->json($this->fresh($goal, $request));
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'target_amount' => [$req, 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    private function fresh(Goal $goal, Request $request): array
    {
        $goal = Goal::with('account')->withSum('contributions', 'amount')->findOrFail($goal->id);

        return $this->present($goal, CurrencyConverter::for($request->user()));
    }

    private function present(Goal $g, CurrencyConverter $fx): array
    {
        $today = CarbonImmutable::today();
        $target = (float) $g->target_amount;
        $saved = $g->account
            ? max(0.0, $fx->convert($g->account->balance, $g->account->currency, $g->currency))
            : round((float) $g->contributions_sum_amount, 2);
        $remaining = round(max(0.0, $target - $saved), 2);
        $achieved = $remaining <= 0;

        $monthsLeft = null;
        $monthly = null;
        $onTrack = null;
        if ($g->target_date && ! $achieved) {
            $end = CarbonImmutable::parse($g->target_date);
            // Whole months still available, counting the current one.
            $monthsLeft = $end->lt($today) ? 0 : max(1, (int) ceil($today->floatDiffInMonths($end)));
            $monthly = $monthsLeft > 0 ? round($remaining / $monthsLeft, 2) : $remaining;
            // On track = saved at least the straight-line share for the time elapsed.
            $start = CarbonImmutable::parse($g->created_at)->startOfDay();
            $span = max(1, $start->diffInDays($end));
            $expected = $target * min(1, max(0, $start->diffInDays($today)) / $span);
            $onTrack = $end->gte($today) && $saved + 0.005 >= $expected * 0.95;
        }

        return [
            'id' => $g->id, 'name' => $g->name, 'target_amount' => $target, 'currency' => $g->currency,
            'target_date' => $g->target_date?->format('Y-m-d'), 'account_id' => $g->account_id, 'notes' => $g->notes,
            'saved' => round($saved, 2), 'remaining' => $remaining,
            'pct' => round(min(100, $target > 0 ? $saved / $target * 100 : 0), 1),
            'achieved' => $achieved, 'months_left' => $monthsLeft, 'monthly_needed' => $monthly, 'on_track' => $onTrack,
            'base_target' => $fx->toBase($target, $g->currency), 'base_saved' => $fx->toBase($saved, $g->currency),
        ];
    }
}
