<?php

namespace App\Enums;

enum PayoutCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case End = 'end';
}
