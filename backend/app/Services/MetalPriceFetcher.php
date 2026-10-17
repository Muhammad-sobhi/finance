<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Optional daily gold/silver price update from a global spot feed (USD per troy ounce),
 * converted to the owner's base currency per gram with their own USD rate.
 * Local shop prices can differ (premiums, workmanship); the owner can still edit them.
 */
class MetalPriceFetcher
{
    public const GRAMS_PER_OUNCE = 31.1034768;

    /** @return int owners updated */
    public function run(): int
    {
        $n = 0;
        User::where('role', 'owner')->where('auto_metal_prices', true)->each(function (User $owner) use (&$n) {
            $n += $this->forOwner($owner) ? 1 : 0;
        });

        return $n;
    }

    public function forOwner(User $owner): bool
    {
        $fx = CurrencyConverter::for($owner);
        if (! $fx->hasRate('USD')) {
            return false; // no way to convert honestly
        }
        $usd = $fx->rate('USD');
        $prices = [];
        foreach (['XAU' => 'gold_price_24k', 'XAG' => 'silver_price'] as $symbol => $column) {
            $res = Http::timeout(15)->retry(2, 500, throw: false)->get(str_replace('{symbol}', $symbol, config('services.metals.url')));
            $perOunce = $res->successful() ? (float) $res->json('price', 0) : 0.0;
            if ($perOunce > 0) {
                $prices[$column] = round($perOunce / self::GRAMS_PER_OUNCE * $usd, 2);
            }
        }
        if (! $prices) {
            return false;
        }
        $owner->forceFill($prices + ['metal_prices_updated_at' => now()])->save();

        return true;
    }
}
