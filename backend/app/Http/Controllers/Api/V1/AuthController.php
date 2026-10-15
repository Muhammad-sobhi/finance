<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        // Hash even when the email is unknown so response time doesn't reveal which emails exist.
        $valid = Hash::check($data['password'], $user?->password ?? self::dummyHash());

        if (! $user || ! $valid || ! $this->canLogIn($user)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $abilities = $user->isHeir() ? ['read'] : ['*'];
        $token = $user->createToken($data['device_name'] ?? 'web', $abilities)->plainTextToken;

        if ($user->isOwner()) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return response()->json(['token' => $token, 'user' => new UserResource($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return new UserResource($user);
    }

    /** Change name / email. Changing the login email needs the current password. */
    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [Rule::requiredIf(fn () => $request->filled('email') && strtolower($request->input('email')) !== $user->email), 'nullable', 'current_password:sanctum'],
        ]);
        unset($data['current_password']);
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        $user->fill($data)->save();

        return new UserResource($user);
    }

    /** Change password; every other device is signed out. */
    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(10), 'different:current_password'],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $request->input('password')])->save();
        $this->revokeOthers($request);

        return response()->json(['ok' => true]);
    }

    /** Sign out everywhere except this device. */
    public function logoutOthers(Request $request): JsonResponse
    {
        return response()->json(['revoked' => $this->revokeOthers($request)]);
    }

    private function revokeOthers(Request $request): int
    {
        return $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()->id)->delete();
    }

    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('timing-equaliser');
    }

    /** Heir accounts only work once heir access is activated (heir module). */
    private function canLogIn(User $user): bool
    {
        return $user->isOwner() || ($user->owner !== null && $user->owner->heir_access_activated_at !== null);
    }
}
