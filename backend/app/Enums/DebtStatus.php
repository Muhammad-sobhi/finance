<?php

namespace App\Enums;

enum DebtStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
}
