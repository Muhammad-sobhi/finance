<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor (authenticator apps) with single-use recovery codes.
 * Secrets are encrypted at rest, recovery codes are stored hashed, and each
 * time-step can be used once (no replay of an intercepted code).
 */
class TwoFactor
{
    private const CHALLENGE_MINUTES = 5;

    public const TRUST_DAYS = 30;

    public function __construct(private readonly Google2FA $totp) {}

    /** Start setup: a new pending secret (not active until confirmed with a code). */
    public function begin(User $user): array
    {
        $secret = $this->totp->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => null])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->totp->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ];
    }

    /** Confirm setup with a first valid code; returns the recovery codes (shown once). */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! $this->checkCode($user, $code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $this->forgetDevices($user);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /** A 6-digit authenticator code or an unused recovery code. */
    public function verify(User $user, ?string $code, ?string $recovery): bool
    {
        if ($code) {
            return $this->checkCode($user, $code);
        }
        if ($recovery) {
            return $this->useRecoveryCode($user, $recovery);
        }

        return false;
    }

    /** @return list<string> plain codes, shown to the user once */
    public function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes)))])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return $user->two_factor_recovery_codes ? count(json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true)) : 0;
    }

    /** Remember this device: returns a random token for the device; only its hash is stored. */
    public function trustDevice(User $user, ?string $name): string
    {
        $token = Str::random(64);
        DB::table('trusted_devices')->insert([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'name' => $name ? mb_substr($name, 0, 120) : null,
            'expires_at' => now()->addDays(self::TRUST_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    public function isTrusted(User $user, ?string $token): bool
    {
        if (! $token) {
            return false;
        }
        $row = DB::table('trusted_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())->first();
        if ($row) {
            DB::table('trusted_devices')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return $row !== null;
    }

    /** Forget every trusted device (password change, sign out others, 2FA off). */
    public function forgetDevices(User $user): void
    {
        DB::table('trusted_devices')->where('user_id', $user->id)->delete();
    }

    /** Short-lived, encrypted proof that the password step passed. */
    public function challengeFor(User $user): string
    {
        return Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => now()->addMinutes(self::CHALLENGE_MINUTES)->timestamp]));
    }

    public function userForChallenge(string $challenge): ?User
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true);
        } catch (\Throwable) {
            return null;
        }

        return ($data['exp'] ?? 0) >= now()->timestamp ? User::find($data['uid'] ?? 0) : null;
    }

    private function checkCode(User $user, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || ! $user->two_factor_secret) {
            return false;
        }
        $secret = Crypt::decryptString($user->two_factor_secret);
        // verifyKeyNewer rejects any time-step at or before the last one used.
        // Always pass a previous step (0 at first use) so the library returns the matched step, not just true.
        $step = $this->totp->verifyKeyNewer($secret, $code, (int) ($user->two_factor_last_step ?? 0), 1);
        if ($step === false) {
            return false;
        }
        $user->forceFill(['two_factor_last_step' => (int) $step])->save();

        return true;
    }

    private function useRecoveryCode(User $user, string $plain): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }
        $hashes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);
        foreach ($hashes as $i => $hash) {
            if (Hash::check(Str::lower(trim($plain)), $hash)) {
                unset($hashes[$i]); // single use
                $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($hashes)))])->save();

                return true;
            }
        }

        return false;
    }
}
