<?php

namespace App\Providers;

use App\Models\Bill;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\GameyaPayment;
use App\Models\IncomeSource;
use App\Models\InstallmentPayment;
use App\Models\ProjectPayment;
use App\Models\Subscription;
use App\Models\ZakatPayment;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Stable names for transaction sources (survive class renames).
        Relation::morphMap([
            'debt' => Debt::class,
            'debt_payment' => DebtPayment::class,
            'bill' => Bill::class,
            'subscription' => Subscription::class,
            'income_source' => IncomeSource::class,
            'project_payment' => ProjectPayment::class,
            'zakat_payment' => ZakatPayment::class,
            'installment_payment' => InstallmentPayment::class,
            'gameya_payment' => GameyaPayment::class,
        ]);

        // Brute-force protection: per email+IP, plus a looser per-IP ceiling.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Password / 2FA settings changes: generous for typos, too slow for guessing.
        RateLimiter::for('account-security', fn (Request $request) => Limit::perMinute(10)->by('acct|'.($request->user()?->id ?: $request->ip())));

        // Second login step: 5 tries/min per IP (a 6-digit code must not be guessable).
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by('2fa|'.$request->ip()));

        // Heir requests: few per hour per IP and per email (and the response never reveals a match).
        RateLimiter::for('heir-request', fn (Request $request) => [
            Limit::perHour(5)->by('ip|'.$request->ip()),
            Limit::perHour(3)->by('email|'.strtolower((string) $request->input('email'))),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));
    }
}
