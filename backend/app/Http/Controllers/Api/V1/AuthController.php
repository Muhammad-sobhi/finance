<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $this->canLogIn($user)) {
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

    /** Heir accounts only work once heir access is activated (heir module). */
    private function canLogIn(User $user): bool
    {
        return $user->isOwner() || ($user->owner !== null && $user->owner->heir_access_activated_at !== null);
    }
}
