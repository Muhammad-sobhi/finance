<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Services\CurrencyConverter;
use App\Services\InstallmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InstallmentController extends Controller
{
    public function __construct(private readonly InstallmentService $service) {}

    /** Active plans first (by next due date), finished ones last. Meta: what is still owed, in base. */
    public function index(Request $request): JsonResponse
    {
        $fx = CurrencyConverter::for($request->user());
        $plans = InstallmentPlan::ownedBy($request->user())->withCount('payments')->get();
        $rows = $plans->map(fn (InstallmentPlan $p) => $this->present($p))
            ->sortBy([['done', 'asc'], ['next_due', 'asc']])->values();
        $monthEnd = CarbonImmutable::today()->endOfMonth();

        return response()->json(['data' => $rows, 'meta' => [
            'base_currency' => $fx->base,
            'remaining' => round($plans->sum(fn ($p) => $fx->toBase($p->remaining(), $p->currency)), 2),
            'this_month' => round($plans->sum(fn ($p) => $fx->toBase($p->remainingUntil($monthEnd), $p->currency)), 2),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', InstallmentPlan::class);
        $plan = $request->user()->installmentPlans()->create($this->validated($request, null));

        return response()->json($this->present($plan->loadCount('payments')), 201);
    }

    public function show(InstallmentPlan $installment): JsonResponse
    {
        Gate::authorize('view', $installment);
        $installment->loadCount('payments');

        return response()->json($this->present($installment) + [
            'payments' => $installment->payments->map(fn (InstallmentPayment $p) => [
                'id' => $p->id, 'number' => $p->number, 'amount' => (float) $p->amount, 'date' => $p->date->format('Y-m-d'), 'account_id' => $p->account_id,
            ])->reverse()->values(),
        ]);
    }

    public function update(Request $request, InstallmentPlan $installment): JsonResponse
    {
        Gate::authorize('update', $installment);
        $installment->update($this->validated($request, $installment));

        return response()->json($this->present($installment->loadCount('payments')));
    }

    public function destroy(InstallmentPlan $installment): JsonResponse
    {
        Gate::authorize('delete', $installment);
        $this->service->deletePlan($installment);

        return response()->json(null, 204);
    }

    public function pay(Request $request, InstallmentPlan $installment): JsonResponse
    {
        Gate::authorize('update', $installment);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
        ]);
        $this->service->payInstallment($installment, $data);

        return response()->json($this->present($installment->loadCount('payments')), 201);
    }

    public function deletePayment(InstallmentPlan $installment, InstallmentPayment $payment): JsonResponse
    {
        Gate::authorize('update', $installment);
        abort_unless($payment->installment_plan_id === $installment->id, 404);
        $this->service->deleteInstallment($payment);

        return response()->json($this->present($installment->loadCount('payments')));
    }

    private function validated(Request $request, ?InstallmentPlan $plan): array
    {
        $req = $plan ? 'sometimes' : 'required';
        $uid = $request->user()->id;
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'installment_amount' => [$req, 'numeric', 'gt:0', 'max:9999999999999'],
            // Can't shrink below what was already paid.
            'count' => [$req, 'integer', 'min:'.max(1, $plan?->paidCount() ?? 1), 'max:600'],
            'first_due_date' => [$req, 'date_format:Y-m-d'],
            'cycle' => ['sometimes', Rule::in(['weekly', 'monthly', 'quarterly'])],
            'down_payment' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $uid)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $uid)->where('type', CategoryType::Expense->value)],
            'remind_days' => ['nullable', 'integer', 'between:0,60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    private function present(InstallmentPlan $p): array
    {
        $paid = $p->paidCount();
        $next = $p->nextDue();

        return [
            'id' => $p->id, 'name' => $p->name, 'provider' => $p->provider, 'currency' => $p->currency,
            'installment_amount' => (float) $p->installment_amount, 'count' => $p->count, 'cycle' => $p->cycle->value,
            'first_due_date' => $p->first_due_date->format('Y-m-d'), 'down_payment' => $p->down_payment !== null ? (float) $p->down_payment : null,
            'account_id' => $p->account_id, 'category_id' => $p->category_id, 'remind_days' => $p->remind_days, 'notes' => $p->notes,
            'paid_count' => $paid, 'remaining' => $p->remaining(), 'total' => round($p->count * (float) $p->installment_amount, 2),
            'next_due' => $next?->toDateString(), 'days' => $next ? (int) CarbonImmutable::today()->diffInDays($next, false) : null,
            'last_due' => $p->dueOf($p->count)->toDateString(), 'done' => $paid >= $p->count,
        ];
    }
}
