<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ZakatPayment;
use App\Services\ZakatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ZakatController extends Controller
{
    public function __construct(private readonly ZakatService $service) {}

    public function show(Request $request): JsonResponse
    {
        $owner = $request->user()->dataOwner();

        return response()->json($this->service->compute($owner) + [
            'payments' => $owner->zakatPayments()->orderByDesc('date')->orderByDesc('id')->limit(20)->get()
                ->map(fn (ZakatPayment $p) => [
                    'id' => $p->id, 'amount' => (float) $p->amount, 'date' => $p->date->format('Y-m-d'),
                    'zakatable_wealth' => (float) $p->zakatable_wealth, 'account_id' => $p->account_id, 'note' => $p->note,
                ]),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner->isOwner(), 403);
        $data = $request->validate([
            'nisab' => ['sometimes', Rule::in(['gold', 'silver'])],
            'calendar' => ['sometimes', Rule::in(['hijri', 'gregorian'])],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'deduct_debts' => ['sometimes', 'boolean'],
        ]);
        $owner->update(collect($data)->mapWithKeys(fn ($v, $k) => ["zakat_{$k}" => $v])->all());

        return $this->show($request);
    }

    public function pay(Request $request): JsonResponse
    {
        Gate::authorize('create', ZakatPayment::class);
        $owner = $request->user();
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $owner->id)],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
            'complete' => ['sometimes', 'boolean'],
        ]);
        if (! isset($data['amount']) && $this->service->compute($owner)['due'] <= 0) {
            return response()->json(['message' => 'Enter the amount.', 'errors' => ['amount' => ['Enter the amount.']]], 422);
        }
        $payment = $this->service->pay($owner, $data);

        return response()->json(['id' => $payment->id], 201);
    }

    public function destroyPayment(ZakatPayment $payment): JsonResponse
    {
        Gate::authorize('delete', $payment);
        $this->service->deletePayment($payment);

        return response()->json(null, 204);
    }
}
