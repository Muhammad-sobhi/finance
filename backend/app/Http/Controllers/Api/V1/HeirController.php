<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Heir;
use App\Models\HeirRequest;
use App\Models\TrustedContact;
use App\Models\User;
use App\Services\HeirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Owner's "Heir & security" page (heirs see it read-only). */
class HeirController extends Controller
{
    public function __construct(private readonly HeirService $service) {}

    public function show(Request $request): JsonResponse
    {
        $owner = $request->user()->dataOwner();

        return response()->json([
            'settings' => $this->status($owner),
            'heirs' => $owner->heirs()->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->map(fn (Heir $h) => $this->heir($h)),
            'trusted' => $owner->trustedContacts()->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->map(fn (TrustedContact $c) => $c->only(['id', 'name', 'email', 'phone', 'relation'])),
            'requests' => $owner->heirRequests()->with(['heir', 'contact'])->latest()->limit(50)->get()->map(fn (HeirRequest $r) => [
                'id' => $r->id, 'heir' => $r->heir?->name, 'status' => $r->status, 'contact' => $r->contact?->name,
                'created_at' => $r->created_at->toIso8601String(), 'activates_at' => $r->activates_at?->toIso8601String(),
            ]),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner->isOwner(), 403);
        $owner->update($request->validate([
            'heir_enabled' => ['sometimes', 'boolean'],
            'heir_inactivity_days' => ['sometimes', 'integer', 'between:7,3650'],
            'heir_grace_days' => ['sometimes', 'integer', 'between:0,365'],
        ]));

        return response()->json($this->status($owner->fresh()));
    }

    public function checkIn(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $this->service->checkIn($request->user());

        return response()->json($this->status($request->user()->fresh()));
    }

    public function revoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $this->service->revoke($request->user());

        return response()->json($this->status($request->user()->fresh()));
    }

    public function storeHeir(Request $request): JsonResponse
    {
        Gate::authorize('create', Heir::class);
        $heir = $request->user()->heirs()->create($this->heirData($request, true));

        return response()->json($this->heir($heir), 201);
    }

    public function updateHeir(Request $request, Heir $heir): JsonResponse
    {
        Gate::authorize('update', $heir);
        $heir->update($this->heirData($request, false));

        return response()->json($this->heir($heir->fresh()));
    }

    public function destroyHeir(Heir $heir): JsonResponse
    {
        Gate::authorize('delete', $heir);
        $heir->login?->tokens()->delete();
        $heir->login?->delete();
        $heir->delete();

        return response()->json(null, 204);
    }

    /** Re-send the set-password email (only while access is active). */
    public function inviteHeir(Heir $heir): JsonResponse
    {
        Gate::authorize('update', $heir);
        abort_unless($heir->user->heirAccessActive(), 409, 'Heir access is not active.');
        $this->service->invite($heir);

        return response()->json(['ok' => true]);
    }

    public function storeContact(Request $request): JsonResponse
    {
        Gate::authorize('create', TrustedContact::class);
        $c = $request->user()->trustedContacts()->create($this->contactData($request, true));

        return response()->json($c->only(['id', 'name', 'email', 'phone', 'relation']), 201);
    }

    public function updateContact(Request $request, TrustedContact $contact): JsonResponse
    {
        Gate::authorize('update', $contact);
        $contact->update($this->contactData($request, false));

        return response()->json($contact->only(['id', 'name', 'email', 'phone', 'relation']));
    }

    public function destroyContact(TrustedContact $contact): JsonResponse
    {
        Gate::authorize('delete', $contact);
        $contact->delete();

        return response()->json(null, 204);
    }

    public function cancelRequest(HeirRequest $heirRequest): JsonResponse
    {
        Gate::authorize('update', $heirRequest);
        $this->service->cancel($heirRequest);

        return response()->json(['ok' => true]);
    }

    private function status(User $owner): array
    {
        $last = $owner->last_activity_at ?? $owner->created_at;
        $inactive = (int) $last->diffInDays(now());
        $state = match (true) {
            $owner->heirAccessActive() => 'triggered',
            $owner->heir_warning_started_at !== null => 'warning',
            $owner->heir_enabled => 'armed',
            default => 'off',
        };

        return [
            'heir_enabled' => $owner->heir_enabled,
            'heir_inactivity_days' => $owner->heir_inactivity_days,
            'heir_grace_days' => $owner->heir_grace_days,
            'last_activity_at' => $last->toIso8601String(),
            'inactive_days' => $inactive,
            'days_until_warning' => max(0, $owner->heir_inactivity_days - $inactive),
            'grace_ends_at' => $owner->heir_warning_started_at?->addDays($owner->heir_grace_days)->toIso8601String(),
            'activated_at' => $owner->heir_access_activated_at?->toIso8601String(),
            'state' => $state,
        ];
    }

    private function heir(Heir $h): array
    {
        return $h->only(['id', 'name', 'relation', 'phone', 'email', 'national_id', 'address', 'notes']) + [
            'has_login' => $h->login_user_id !== null, 'invited_at' => $h->invited_at?->toIso8601String(),
        ];
    }

    private function heirData(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'relation' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => [$req, 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        return $data;
    }

    private function contactData(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'email' => [$req, 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'relation' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
