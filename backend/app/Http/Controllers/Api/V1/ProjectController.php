<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Services\CurrencyConverter;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Freelance / project income: agreed amount, payments received, outstanding. */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(Project::STATUSES)]]);
        $fx = CurrencyConverter::for($request->user());
        $items = Project::ownedBy($request->user())->with('payments')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByRaw("case status when 'active' then 0 when 'done' then 1 else 2 end")
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id')
            ->get();
        $active = $items->where('status', 'active');

        return response()->json([
            'data' => $items->map(fn (Project $p) => $this->present($p)),
            'meta' => [
                'base_currency' => $fx->base,
                'outstanding' => round($active->sum(fn (Project $p) => $fx->toBase($p->outstanding(), $p->currency)), 2),
                'received' => round($items->sum(fn (Project $p) => $fx->toBase($p->received(), $p->currency)), 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Project::class);
        $p = $request->user()->projects()->create($this->validated($request, true));

        return response()->json($this->present($p->fresh()), 201);
    }

    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($this->present($project) + [
            'payments' => $project->payments->map(fn (ProjectPayment $x) => [
                'id' => $x->id, 'amount' => (float) $x->amount, 'date' => $x->date->format('Y-m-d'), 'account_id' => $x->account_id, 'note' => $x->note,
            ]),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);
        $project->update($this->validated($request, false));

        return response()->json($this->present($project->fresh()));
    }

    public function destroy(Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);
        $this->service->delete($project);

        return response()->json(null, 204);
    }

    public function addPayment(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $data['date'] ??= now()->toDateString();
        $payment = $this->service->addPayment($project, $data);

        return response()->json(['id' => $payment->id, 'project' => $this->present($project->fresh())], 201);
    }

    public function deletePayment(Project $project, ProjectPayment $payment): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($payment->project_id === $project->id, 404);
        $this->service->deletePayment($payment);

        return response()->json($this->present($project->fresh()));
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'client' => [$req, 'string', 'max:120'],
            'name' => [$req, 'string', 'max:160'],
            'amount' => [$req, 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->where('type', 'income')],
            'remind_days' => ['nullable', 'integer', 'between:0,365'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    private function present(Project $p): array
    {
        return [
            'id' => $p->id, 'client' => $p->client, 'name' => $p->name, 'amount' => (float) $p->amount, 'currency' => $p->currency,
            'start_date' => $p->start_date?->format('Y-m-d'), 'due_date' => $p->due_date?->format('Y-m-d'), 'status' => $p->status,
            'category_id' => $p->category_id, 'remind_days' => $p->remind_days, 'notes' => $p->notes,
            'received' => $p->received(), 'outstanding' => $p->outstanding(),
            'days' => $p->due_date ? (int) now()->startOfDay()->diffInDays($p->due_date, false) : null,
        ];
    }
}
