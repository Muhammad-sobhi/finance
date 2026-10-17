<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssetType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\CurrencyConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owner = $request->user()->dataOwner();
        $fx = CurrencyConverter::for($owner);
        $items = Asset::ownedBy($request->user())->get()
            ->sortBy([fn (Asset $a, Asset $b) => $a->type->value <=> $b->type->value, fn (Asset $a, Asset $b) => strnatcasecmp($a->name, $b->name)])
            ->map(fn (Asset $a) => $this->present($a, $owner, $fx));

        return response()->json([
            'data' => $items->sortByDesc('base_value')->values(),
            'meta' => ['base_currency' => $fx->base, 'total' => round($items->sum('base_value'), 2)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Asset::class);
        $asset = $request->user()->assets()->create($this->validated($request, true));

        return response()->json($this->present($asset->fresh(), $request->user(), CurrencyConverter::for($request->user())), 201);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);
        $owner = $request->user()->dataOwner();

        return response()->json($this->present($asset, $owner, CurrencyConverter::for($owner)));
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);
        $asset->update($this->validated($request, false));

        return response()->json($this->present($asset->fresh(), $request->user(), CurrencyConverter::for($request->user())));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        Gate::authorize('delete', $asset);
        $asset->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'type' => [$req, Rule::enum(AssetType::class)],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'current_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'grams' => ['nullable', 'numeric', 'min:0', 'max:99999999', Rule::requiredIf(fn () => in_array($request->input('type'), ['gold', 'silver'], true))],
            'karat' => ['nullable', 'integer', Rule::in([9, 10, 12, 14, 18, 21, 22, 24])],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    private function present(Asset $a, User $owner, CurrencyConverter $fx): array
    {
        [$value, $currency] = $a->valuation($owner);

        return [
            'id' => $a->id, 'name' => $a->name, 'type' => $a->type->value, 'currency' => $a->currency,
            'purchase_price' => $a->purchase_price !== null ? (float) $a->purchase_price : null,
            'purchase_date' => $a->purchase_date?->format('Y-m-d'),
            'current_value' => $a->current_value !== null ? (float) $a->current_value : null,
            'grams' => $a->grams !== null ? (float) $a->grams : null, 'karat' => $a->karat,
            'location' => $a->location, 'notes' => $a->notes,
            // Valuation actually used (auto for metals), in its currency and in base.
            'value' => $value, 'value_currency' => $currency, 'base_value' => $fx->toBase($value, $currency),
            'auto_valued' => $a->type->isMetal(),
        ];
    }
}
