<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Owner-wide settings plus the exchange-rate table. */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()->dataOwner()));
    }

    public function update(Request $request): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner->isOwner(), 403);
        $data = $request->validate([
            'base_currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'gold_price_24k' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'silver_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'alert_days' => ['sometimes', 'integer', 'between:0,60'],
            'email_alerts' => ['sometimes', 'boolean'],
            'auto_fetch_rates' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['base_currency'])) {
            $data['base_currency'] = strtoupper($data['base_currency']);
            // Rates are relative to base; a new base makes the old table meaningless for it.
            if ($data['base_currency'] !== $owner->base_currency) {
                $owner->exchangeRates()->where('currency', $data['base_currency'])->delete();
            }
        }
        $owner->update($data);

        return response()->json($this->present($owner->fresh()));
    }

    public function storeRate(Request $request): JsonResponse
    {
        Gate::authorize('create', ExchangeRate::class);
        $owner = $request->user();
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha', Rule::notIn([$owner->base_currency, strtolower($owner->base_currency)])],
            'rate' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'auto_update' => ['sometimes', 'boolean'],
        ]);
        $data['currency'] = strtoupper($data['currency']);
        $rate = $owner->exchangeRates()->updateOrCreate(['currency' => $data['currency']], $data);

        return response()->json($this->rate($rate), 201);
    }

    public function updateRate(Request $request, ExchangeRate $rate): JsonResponse
    {
        Gate::authorize('update', $rate);
        $rate->update($request->validate([
            'rate' => ['sometimes', 'numeric', 'gt:0', 'max:99999999'],
            'auto_update' => ['sometimes', 'boolean'],
        ]));

        return response()->json($this->rate($rate));
    }

    public function destroyRate(ExchangeRate $rate): JsonResponse
    {
        Gate::authorize('delete', $rate);
        $rate->delete();

        return response()->json(null, 204);
    }

    private function present(User $owner): array
    {
        return [
            'base_currency' => $owner->base_currency,
            'gold_price_24k' => (float) $owner->gold_price_24k,
            'silver_price' => (float) $owner->silver_price,
            'alert_days' => $owner->alert_days,
            'email_alerts' => $owner->email_alerts,
            'auto_fetch_rates' => $owner->auto_fetch_rates,
            'rates' => $owner->exchangeRates()->orderBy('currency')->get()->map(fn ($r) => $this->rate($r)),
        ];
    }

    private function rate(ExchangeRate $r): array
    {
        return ['id' => $r->id, 'currency' => $r->currency, 'rate' => (float) $r->rate, 'auto_update' => $r->auto_update, 'updated_at' => $r->updated_at?->toIso8601String()];
    }
}
