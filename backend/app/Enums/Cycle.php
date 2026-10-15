<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum Cycle: string
{
    case Once = 'once';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /** Next due date after `$date`; null for one-off items. */
    public function next(CarbonImmutable $date): ?CarbonImmutable
    {
        return match ($this) {
            self::Once => null,
            self::Weekly => $date->addWeek(),
            self::Monthly => $date->addMonthNoOverflow(),
            self::Quarterly => $date->addMonthsNoOverflow(3),
            self::Yearly => $date->addYearNoOverflow(),
        };
    }

    /** Factor that converts one payment per cycle into a monthly cost. */
    public function monthlyFactor(): float
    {
        return match ($this) {
            self::Once => 0.0,
            self::Weekly => 52 / 12,
            self::Monthly => 1.0,
            self::Quarterly => 1 / 3,
            self::Yearly => 1 / 12,
        };
    }
}
