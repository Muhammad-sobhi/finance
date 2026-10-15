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
use Illuminate\Validation\Rules\Password;

/**
 * Emailed one-click actions, protected by signed, expiring URLs (no login).
 * GET describes the action for the confirmation screen; POST performs it.
 */
class LinkController extends Controller
{
    public function __construct(private readonly HeirService $service) {}

    public function checkIn(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isOwner(), 404);
        if ($request->isMethod('post')) {
            $this->service->checkIn($user);

            return response()->json(['done' => true]);
        }

        return response()->json(['action' => 'check_in', 'owner' => $user->name]);
    }

    public function confirm(Request $request, HeirRequest $heirRequest, TrustedContact $contact): JsonResponse
    {
        abort_unless($contact->user_id === $heirRequest->user_id, 404);
        if ($request->isMethod('post')) {
            $req = $this->service->confirm($heirRequest, $contact);

            return response()->json(['done' => true, 'activates_at' => $req->activates_at?->toIso8601String()]);
        }

        return response()->json(['action' => 'confirm', 'heir' => $heirRequest->heir->name, 'owner' => $heirRequest->user->name, 'contact' => $contact->name, 'status' => $heirRequest->status]);
    }

    public function cancel(Request $request, HeirRequest $heirRequest): JsonResponse
    {
        if ($request->isMethod('post')) {
            $this->service->cancel($heirRequest);

            return response()->json(['done' => true]);
        }

        return response()->json(['action' => 'cancel', 'heir' => $heirRequest->heir->name, 'status' => $heirRequest->status]);
    }

    public function activate(Request $request, Heir $heir): JsonResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['password' => ['required', 'confirmed', Password::min(10)]]);
            $user = $this->service->setPassword($heir, $data['password']);

            return response()->json(['done' => true, 'email' => $user->email]);
        }

        return response()->json(['action' => 'activate', 'heir' => $heir->name, 'email' => $heir->email, 'owner' => $heir->user->name, 'active' => $heir->user->heirAccessActive()]);
    }
}
