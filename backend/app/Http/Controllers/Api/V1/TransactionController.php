<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\BudgetService;
use App\Services\CurrencyConverter;
use App\Services\TransactionService;
use App\Services\UnusualSpend;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $service, private readonly BudgetService $budgets, private readonly UnusualSpend $unusual) {}

    /**
     * One month of transactions (newest first) with month and per-day totals in base currency.
     * The client paginates to fit the screen, so the whole filtered month is returned.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'category_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:expense,income,transfer'],
        ]);

        $start = CarbonImmutable::createFromFormat('!Y-m', $request->input('month', now()->format('Y-m')));
        $end = $start->endOfMonth();
        $fx = CurrencyConverter::for($request->user());

        $rows = Transaction::ownedBy($request->user())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('account_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('account_id', $request->integer('account_id'))
                ->orWhere('to_account_id', $request->integer('account_id'))))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderByDesc('date')->orderByDesc('id')
            ->get();

        $totals = ['expense' => 0.0, 'income' => 0.0];
        $days = [];
        foreach ($rows as $tx) {
            $tx->base_amount = $fx->toBase($tx->amount, $tx->currency);
            if ($tx->type === TransactionType::Transfer || $tx->isDebtMovement()) {
                continue;
            }
            $key = $tx->type->value;
            $day = $tx->date->format('Y-m-d');
            $days[$day] ??= ['expense' => 0.0, 'income' => 0.0];
            $days[$day][$key] = round($days[$day][$key] + $tx->base_amount, 2);
            $totals[$key] = round($totals[$key] + $tx->base_amount, 2);
        }

        return response()->json([
            'data' => TransactionResource::collection($rows),
            'meta' => [
                'month' => $start->format('Y-m'),
                'base_currency' => $fx->base,
                'totals' => $totals + ['net' => round($totals['income'] - $totals['expense'], 2)],
                'days' => (object) $days,
            ],
        ]);
    }

    /** Defaults for the quick-add sheet. */
    public function quickMeta(Request $request): JsonResponse
    {
        $last = Transaction::ownedBy($request->user())->latest('id')->first();

        return response()->json([
            'last_account_id' => $last?->account_id,
            'last_category_id' => $last?->category_id,
        ]);
    }

    public function store(TransactionRequest $request): JsonResponse
    {
        $tx = $this->service->create($request->user(), $request->validated());
        $this->budgets->watch($request->user());
        $this->unusual->check($request->user(), $tx);

        return (new TransactionResource($tx->refresh()))->response()->setStatusCode(201);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        Gate::authorize('view', $transaction);

        return new TransactionResource($transaction);
    }

    public function update(TransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $this->guardManaged($transaction);

        $tx = $this->service->update($transaction, $request->validated())->refresh();
        $this->budgets->watch($request->user());

        return new TransactionResource($tx);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        Gate::authorize('delete', $transaction);
        $this->guardManaged($transaction);
        $this->service->delete($transaction);

        return response()->json(null, 204);
    }

    /** Loan/repayment and project-payment transactions are owned by their record; edit them there. */
    private function guardManaged(Transaction $transaction): void
    {
        if ($code = $transaction->managedCode()) {
            abort(response()->json(['message' => 'Edit this from the record it belongs to.', 'code' => $code], 409));
        }
    }
}
