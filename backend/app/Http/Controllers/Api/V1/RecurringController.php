<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Cycle;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Bill;
use App\Models\IncomeSource;
use App\Models\Subscription;
use App\Services\CurrencyConverter;
use App\Services\RecurringService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Shared CRUD + "mark paid" / "received" for bills, subscriptions and recurring income. */
abstract class RecurringController extends Controller
{
    /** @return class-string<Bill|Subscription|IncomeSource> */
    abstract protected function model(): string;

    /** Extra validation rules for the concrete type. */
    abstract protected function extraRules(bool $create): array;

    public function __construct(protected readonly RecurringService $service) {}

    protected function query(Request $request)
    {
        $class = $this->model();

        return $class::ownedBy($request->user())
            ->orderByDesc('is_active')->orderBy($class::DUE_COLUMN)->orderBy('name');
    }

    protected function rules(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $owner = $request->user()->id;

        return [
            'name' => [$req, 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $owner)->where('type', $this->categoryType())],
            'amount' => [$req, 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'cycle' => [$req, Rule::enum(Cycle::class)],
            'remind_days' => ['nullable', 'integer', 'between:0,365'],
            'auto_pay' => ['sometimes', 'boolean'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $owner)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->extraRules($create);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', $this->model());
        $data = $request->validate($this->rules($request, true));
        $data['currency'] = strtoupper($data['currency']);
        $item = $request->user()->{$this->relation()}()->create($data);

        return response()->json($this->present($item->fresh()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $item = $this->find($id);
        Gate::authorize('view', $item);

        return response()->json($this->present($item));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = $this->find($id);
        Gate::authorize('update', $item);
        $data = $request->validate($this->rules($request, false));
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        $item->update($data);

        return response()->json($this->present($item->fresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        $item = $this->find($id);
        Gate::authorize('delete', $item);
        $item->delete(); // past payment transactions stay in the ledger

        return response()->json(null, 204);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $item = $this->find($id);
        Gate::authorize('update', $item);
        $data = $request->validate([
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        abort_unless($item->is_active, 422, 'This item is no longer active.');
        $tx = $this->service->pay($item, $data);

        return response()->json(['transaction' => new TransactionResource($tx->refresh()), 'item' => $this->present($item->fresh())]);
    }

    protected function find(int $id): Model
    {
        return $this->model()::findOrFail($id);
    }

    protected function relation(): string
    {
        return match ($this->model()) {
            Bill::class => 'bills',
            Subscription::class => 'subscriptions',
            IncomeSource::class => 'incomeSources',
        };
    }

    protected function categoryType(): string
    {
        return $this->model()::TX_TYPE;
    }

    protected function present(Bill|Subscription|IncomeSource $i): array
    {
        $due = $i->dueDate();

        return [
            'id' => $i->id, 'name' => $i->name, 'category_id' => $i->category_id,
            'amount' => (float) $i->amount, 'currency' => $i->currency, 'cycle' => $i->cycle->value,
            'remind_days' => $i->remind_days, 'auto_pay' => $i->auto_pay, 'account_id' => $i->account_id,
            'notes' => $i->notes, 'is_active' => $i->is_active, 'last_paid_at' => $i->last_paid_at?->format('Y-m-d'),
            $i::DUE_COLUMN => $due->toDateString(),
            'days' => (int) now()->startOfDay()->diffInDays($due, false),
        ];
    }

    protected function base(Request $request): CurrencyConverter
    {
        return CurrencyConverter::for($request->user());
    }
}
