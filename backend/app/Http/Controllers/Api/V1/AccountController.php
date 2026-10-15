<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $fx = CurrencyConverter::for($request->user());
        $accounts = Account::ownedBy($request->user())
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort')->orderBy('name')->get()
            ->each(fn (Account $a) => $a->base_balance = $fx->toBase($a->balance, $a->currency));

        return AccountResource::collection($accounts);
    }

    public function store(AccountRequest $request): JsonResponse
    {
        $account = $request->user()->accounts()->create($request->validated());

        return (new AccountResource($account->refresh()))->response()->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        Gate::authorize('view', $account);

        return new AccountResource($account);
    }

    public function update(AccountRequest $request, Account $account): AccountResource
    {
        $data = $request->validated();
        if (isset($data['currency']) && $data['currency'] !== $account->currency && $this->hasTransactions($account)) {
            abort(response()->json(['message' => 'Currency cannot change once the account has transactions.', 'code' => 'has_transactions'], 422));
        }
        $account->update($data);

        return new AccountResource($account);
    }

    public function destroy(Account $account): JsonResponse
    {
        Gate::authorize('delete', $account);
        // Deleting would silently rewrite history (and other accounts' balances via transfers).
        if ($this->hasTransactions($account)) {
            return response()->json(['message' => 'Account has transactions; deactivate it instead.', 'code' => 'has_transactions'], 409);
        }
        $account->delete();

        return response()->json(null, 204);
    }

    private function hasTransactions(Account $account): bool
    {
        return Transaction::where('account_id', $account->id)->orWhere('to_account_id', $account->id)->exists();
    }
}
