<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\InfoAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records sign-ins (and failed attempts on real accounts) and tells the user, by email
 * as well, when a sign-in comes from a browser/device they haven't used recently.
 */
class SignInLog
{
    public const KEEP = 200;            // events kept per user

    public const KNOWN_FOR_DAYS = 180;  // a device seen within this window isn't "new"

    public function success(User $user, Request $request, string $method): void
    {
        $device = self::device((string) $request->userAgent());
        $fp = sha1($device);
        $hadAny = DB::table('login_events')->where('user_id', $user->id)->where('success', true)->exists();
        $known = DB::table('login_events')->where('user_id', $user->id)->where('success', true)->where('fingerprint', $fp)
            ->where('created_at', '>=', now()->subDays(self::KNOWN_FOR_DAYS))->exists();
        $this->write($user, $request, $method, true, $device, $fp);

        if ($hadAny && ! $known) {
            $user->notify(new InfoAlert('new_sign_in', [
                'device' => ['en' => $device, 'ar' => str_replace(' on ', ' على ', $device)], 'ip' => (string) $request->ip(),
                'time' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            ], '/settings', always: true));
        }
    }

    public function failure(User $user, Request $request, string $method): void
    {
        $device = self::device((string) $request->userAgent());
        $this->write($user, $request, $method, false, $device, sha1($device));
    }

    /** "Chrome on Android", "Safari on iPhone", "Firefox on Windows"… from the User-Agent. */
    public static function device(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            $ua === '' => 'Unknown',
            default => 'App',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$os}";
    }

    private function write(User $user, Request $request, string $method, bool $ok, string $device, string $fp): void
    {
        DB::table('login_events')->insert([
            'user_id' => $user->id, 'success' => $ok, 'method' => $method, 'device' => $device,
            'fingerprint' => $fp, 'ip' => $request->ip(), 'created_at' => now(),
        ]);
        $cutoff = DB::table('login_events')->where('user_id', $user->id)->orderByDesc('id')->skip(self::KEEP)->value('id');
        if ($cutoff) {
            DB::table('login_events')->where('user_id', $user->id)->where('id', '<=', $cutoff)->delete();
        }
    }
}
