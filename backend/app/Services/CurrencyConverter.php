<?php

namespace App\Services;

use App\Models\User;

/**
 * Converts amounts between currencies using the owner's exchange-rate table.
 * Rates are stored as "units of base currency per 1 unit of currency".
 */
class CurrencyConverter
{
    /** @param array<string, float> $rates */
    public function __construct(public readonly string $base, private array $rates) {}

    public static function for(User $user): self
    {
        $owner = $user->dataOwner();
        $rates = $owner->exchangeRates()->pluck('rate', 'currency')->map(fn ($r) => (float) $r)->all();

        return new self($owner->base_currency, $rates);
    }

    /** Rate to base; unknown currencies fall back to 1 so totals never silently drop money. */
    public function rate(string $currency): float
    {
        return $currency === $this->base ? 1.0 : ($this->rates[$currency] ?? 1.0);
    }

    public function hasRate(string $currency): bool
    {
        return $currency === $this->base || isset($this->rates[$currency]);
    }

    public function toBase(float|string|null $amount, string $currency): float
    {
        return round((float) $amount * $this->rate($currency), 2);
    }

    public function convert(float|string $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return round((float) $amount, 2);
        }

        return round((float) $amount * $this->rate($from) / $this->rate($to), 2);
    }
}
