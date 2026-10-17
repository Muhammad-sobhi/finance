<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\InviteMail;
use App\Services\AccountEraser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * For the app's admin: invite new owners (clients, testers) by email, see who joined and
 * delete an account with all its data. Shows names, emails and dates only — never anyone's
 * financial data.
 */
class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->guard($request);
        $rows = User::where('role', UserRole::Owner)->orderByDesc('id')->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_admin' => $u->is_admin,
                'status' => $u->invitePending() ? 'pending' : 'active',
                'invited_at' => $u->invited_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String(),
                'last_activity_at' => $u->last_activity_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function invite(Request $request): JsonResponse
    {
        $admin = $this->guard($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
        ]);
        $user = User::create([
            'name' => $data['name'], 'email' => strtolower($data['email']), 'locale' => $data['locale'] ?? 'ar',
            // Unusable until they choose their own via the emailed link.
            'password' => Str::random(64), 'role' => UserRole::Owner,
        ]);
        $user->forceFill(['invited_at' => now(), 'invited_by' => $admin->id])->save();
        $this->send($user, $admin);

        return response()->json(['id' => $user->id], 201);
    }

    public function resend(Request $request, User $user): JsonResponse
    {
        $admin = $this->guard($request);
        abort_unless($user->invitePending(), 409, 'This account is already active.');
        $user->forceFill(['invited_at' => now()])->save();
        $this->send($user, $admin);

        return response()->json(['ok' => true]);
    }

    /** Withdraw an invitation nobody accepted yet (active accounts are never deleted from here). */
    public function cancel(Request $request, User $user): JsonResponse
    {
        $this->guard($request);
        abort_unless($user->invitePending(), 409, 'Only pending invitations can be cancelled.');
        Password::broker('invites')->deleteToken($user);
        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * Delete an owner and all their data, for good. The admin re-enters their own password
     * and types the user's email to confirm. Admin accounts can't be deleted from here.
     */
    public function destroy(Request $request, User $user, AccountEraser $eraser): JsonResponse
    {
        $admin = $this->guard($request);
        abort_unless($user->isOwner(), 404);
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'confirm_email' => ['required', 'string', function ($attr, $value, $fail) use ($user) {
                if (strtolower(trim((string) $value)) !== strtolower($user->email)) {
                    $fail(__('validation.same', ['attribute' => 'confirm_email', 'other' => 'email']));
                }
            }],
        ]);
        abort_if($user->id === $admin->id || $user->is_admin, 409, 'Admin accounts cannot be deleted here.');
        $eraser->erase($user, $admin, $request->ip());

        return response()->json(null, 204);
    }

    private function send(User $user, User $admin): void
    {
        $user->notify(new InviteMail(Password::broker('invites')->createToken($user), $admin->name));
    }

    private function guard(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->isOwner() && $user->is_admin, 403);

        return $user;
    }
}
