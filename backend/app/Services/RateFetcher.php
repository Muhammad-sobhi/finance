<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Optional auto-update of exchange rates for owners who enabled it. The provider returns
 * "units of X per 1 base"; we store "base per 1 X", so the stored rate is the inverse.
 */
class RateFetcher
{
    /** @return int number of rates updated */
    public function run(): int
    {
        $updated = 0;
        User::where('role', 'owner')->where('auto_fetch_rates', true)->each(function (User $owner) use (&$updated) {
            $updated += $this->forOwner($owner);
        });

        return $updated;
    }

    public function forOwner(User $owner): int
    {
        $rates = $owner->exchangeRates()->get();
        if ($rates->isEmpty()) {
            return 0;
        }
        $res = Http::timeout(15)->retry(2, 500, throw: false)->get(str_replace('{base}', $owner->base_currency, config('services.rates.url')));
        $table = $res->successful() ? (array) $res->json('rates', []) : [];
        $n = 0;
        foreach ($rates as $rate) {
            $perBase = (float) ($table[$rate->currency] ?? 0);
            if ($perBase > 0) {
                $rate->update(['rate' => round(1 / $perBase, 6)]);
                $n++;
            }
        }

        return $n;
    }
}
