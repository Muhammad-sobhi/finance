<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_token' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        // Hash even when the email is unknown so response time doesn't reveal which emails exist.
        $valid = Hash::check($data['password'], $user?->password ?? self::dummyHash());

        if (! $user || ! $valid || ! $this->canLogIn($user)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Second step required: hand back a short-lived challenge instead of a token.
        if ($this->twoFactor->enabled($user) && ! $this->twoFactor->isTrusted($user, $data['device_token'] ?? null)) {
            return response()->json(['two_factor' => true, 'challenge' => $this->twoFactor->challengeFor($user)]);
        }

        return $this->issueToken($user, $data['device_name'] ?? 'web');
    }

    /** Login step 2: authenticator code (or a recovery code) for the challenge from step 1. */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:2000'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:30'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $user = $this->twoFactor->userForChallenge($data['challenge']);
        if (! $user) {
            throw ValidationException::withMessages(['challenge' => 'Sign-in expired. Enter your password again.']);
        }
        if (! $this->twoFactor->verify($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }

        $response = $this->issueToken($user, $data['device_name'] ?? 'web');
        if ($request->boolean('remember')) {
            $response->setData($response->getData(true) + ['device_token' => $this->twoFactor->trustDevice($user, $data['device_name'] ?? null)]);
        }

        return $response;
    }

    /** Start enabling 2FA (needs the current password). */
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:sanctum']]);
        abort_if($this->twoFactor->enabled($request->user()), 409, 'Two-factor is already enabled.');

        return response()->json($this->twoFactor->begin($request->user()));
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->twoFactor->confirm($request->user(), $request->input('code'));
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }

        return response()->json(['recovery_codes' => $codes]);
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:sanctum'], 'code' => ['required', 'string', 'max:30']]);
        $user = $request->user();
        $code = $request->input('code');
        if (! $this->twoFactor->verify($user, strlen(preg_replace('/\D/', '', $code)) === 6 ? $code : null, $code)) {
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }
        $this->twoFactor->disable($user);

        return response()->json(['ok' => true]);
    }

    public function twoFactorRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:sanctum']]);
        abort_unless($this->twoFactor->enabled($request->user()), 409, 'Two-factor is not enabled.');

        return response()->json(['recovery_codes' => $this->twoFactor->newRecoveryCodes($request->user())]);
    }

    public function twoFactorStatus(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $this->twoFactor->enabled($request->user()),
            'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($request->user()),
        ]);
    }

    private function issueToken(User $user, string $device): JsonResponse
    {
        $abilities = $user->isHeir() ? ['read'] : ['*'];
        $token = $user->createToken($device, $abilities)->plainTextToken;

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
        $this->twoFactor->forgetDevices($user);

        return response()->json(['ok' => true]);
    }

    /** Sign out everywhere except this device. */
    public function logoutOthers(Request $request): JsonResponse
    {
        $this->twoFactor->forgetDevices($request->user());

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
