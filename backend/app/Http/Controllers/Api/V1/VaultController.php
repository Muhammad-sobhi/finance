<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Vault;
use App\Models\VaultItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The vault (passwords, bank details, documents…). The browser encrypts and decrypts; this
 * only stores what it is given. Three modes, chosen by the owner:
 *  - pin_heir: PIN to open; a copy of the key for heirs, released once heir access is active.
 *  - pin:      PIN only; nobody else can ever open it (heirs included).
 *  - open:     no PIN; the key copy opens it right after sign-in.
 * Changing the mode/PIN or wiping the vault needs the account password, so a stolen session
 * can't lock the owner out of their own vault.
 */
class VaultController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $owner = $request->user()->dataOwner();
        $vault = Vault::where('user_id', $owner->id)->first();

        return response()->json([
            'vault' => $vault ? $vault->only(['salt', 'iterations', 'iv', 'wrapped_key']) + ['mode' => $this->mode($vault)] : null,
            'items' => $vault ? VaultItem::ownedBy($request->user())->orderBy('id')->get()->map(fn (VaultItem $i) => $this->present($i)) : [],
        ]);
    }

    /** First-time setup: store the wrapped key. */
    public function setup(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        abort_if(Vault::where('user_id', $owner->id)->exists(), 409, 'The vault is already set up.');
        $vault = new Vault($this->keyData($request));
        $vault->user_id = $owner->id;
        $vault->save();

        return response()->json(['ok' => true], 201);
    }

    /** New PIN and/or mode: the browser re-wraps the same vault key; items don't change. */
    public function rekey(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $request->validate(['current_password' => ['required', 'current_password:sanctum']]);
        Vault::where('user_id', $owner->id)->firstOrFail()->update($this->keyData($request));

        return response()->json(['ok' => true]);
    }

    /**
     * The key copy, when this user may have it: the owner of a vault without PIN, or an heir
     * once heir access is active and the owner left a copy.
     */
    public function escrow(Request $request): JsonResponse
    {
        $user = $request->user();
        $vault = Vault::where('user_id', $user->ownerId())->first();
        $allowed = $vault?->hasHeirCopy() && ($user->isHeir() ? $user->owner->heirAccessActive() : ! $vault->hasPin());
        abort_unless($allowed, 403);

        return response()->json(['key' => $vault->escrow_key]);
    }

    /** Forgot the PIN: delete the key and every item, so a new vault can be set up. */
    public function reset(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $request->validate(['current_password' => ['required', 'current_password:sanctum']]);
        DB::transaction(function () use ($owner) {
            VaultItem::where('user_id', $owner->id)->delete();
            Vault::where('user_id', $owner->id)->delete();
        });

        return response()->json(null, 204);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', VaultItem::class);
        $owner = $this->owner($request);
        abort_unless(Vault::where('user_id', $owner->id)->exists(), 409, 'Set up the vault first.');
        $item = new VaultItem($this->itemData($request));
        $item->user_id = $owner->id;
        $item->save();

        return response()->json($this->present($item), 201);
    }

    public function update(Request $request, VaultItem $item): JsonResponse
    {
        Gate::authorize('update', $item);
        $item->update($this->itemData($request));

        return response()->json($this->present($item));
    }

    public function destroy(VaultItem $item): JsonResponse
    {
        Gate::authorize('delete', $item);
        $item->delete();

        return response()->json(null, 204);
    }

    private function owner(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isOwner(), 403);

        return $user;
    }

    /** Validated key fields for the chosen mode; fields the mode doesn't use are cleared. */
    private function keyData(Request $request): array
    {
        $b64 = 'regex:/^[A-Za-z0-9+\/=]+$/';
        $mode = $request->validate(['mode' => ['required', Rule::in(['pin_heir', 'pin', 'open'])]])['mode'];
        $pin = $mode !== 'open' ? 'required' : 'prohibited';
        $copy = $mode !== 'pin' ? 'required' : 'prohibited';
        $data = $request->validate([
            'salt' => [$pin, 'string', 'max:64', $b64],
            'iterations' => [$pin, 'integer', 'min:'.Vault::MIN_ITERATIONS, 'max:10000000'],
            'iv' => [$pin, 'string', 'max:32', $b64],
            'wrapped_key' => [$pin, 'string', 'max:200', $b64],
            // The raw 256-bit vault key, base64 (44 chars).
            'escrow_key' => [$copy, 'string', 'size:44', $b64],
        ]);

        return array_merge(array_fill_keys(['salt', 'iterations', 'iv', 'wrapped_key', 'escrow_key'], null), $data);
    }

    private function mode(Vault $vault): string
    {
        return ! $vault->hasPin() ? 'open' : ($vault->hasHeirCopy() ? 'pin_heir' : 'pin');
    }

    private function itemData(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(VaultItem::KINDS)],
            'iv' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9+\/=]+$/'],
            'ciphertext' => ['required', 'string', 'max:60000', 'regex:/^[A-Za-z0-9+\/=]+$/'],
            'remind_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function present(VaultItem $i): array
    {
        return ['id' => $i->id, 'kind' => $i->kind, 'iv' => $i->iv, 'ciphertext' => $i->ciphertext,
            'remind_on' => $i->remind_on?->format('Y-m-d'), 'updated_at' => $i->updated_at?->toIso8601String()];
    }
}
