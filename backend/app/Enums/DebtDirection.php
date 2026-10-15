<?php

namespace App\Enums;

enum DebtDirection: string
{
    case OwedToMe = 'owed_to_me';
    case IOwe = 'i_owe';
}
