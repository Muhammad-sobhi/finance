<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Something with a due date that may need an alert. */
final class DueItem
{
    public function __construct(
        public readonly string $kind,      // bill | subscription | owed_to_me | i_owe | credit_card | maturity
        public readonly int $id,
        public readonly string $name,
        public readonly CarbonImmutable $due,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?int $remindDays,  // null → owner default
        public readonly string $path,      // SPA route to open
    ) {}

    /** before | due | overdue | null (nothing to say today). */
    public function stage(CarbonImmutable $today, int $defaultDays): ?string
    {
        $days = $this->days($today);
        $remind = $this->remindDays ?? $defaultDays;

        return match (true) {
            $days < 0 => 'overdue',
            $days === 0 => 'due',
            $days <= $remind => 'before',
            default => null,
        };
    }

    public function days(CarbonImmutable $today): int
    {
        return (int) $today->diffInDays($this->due, false);
    }
}
