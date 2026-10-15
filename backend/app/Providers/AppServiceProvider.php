<?php

namespace App\Providers;

use App\Models\Bill;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Subscription;
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
        ]);

        // Brute-force protection: per email+IP, plus a looser per-IP ceiling.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));
    }
}
