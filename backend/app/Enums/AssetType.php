<?php

namespace App\Enums;

enum AssetType: string
{
    case Building = 'building';
    case Land = 'land';
    case Car = 'car';
    case Device = 'device';
    case Gold = 'gold';
    case Silver = 'silver';
    case Jewelry = 'jewelry';
    case ForeignCash = 'foreign_cash';
    case Other = 'other';

    public function isMetal(): bool
    {
        return $this === self::Gold || $this === self::Silver;
    }
}
