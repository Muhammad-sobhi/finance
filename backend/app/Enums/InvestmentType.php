<?php

namespace App\Enums;

enum InvestmentType: string
{
    case Stocks = 'stocks';
    case Fund = 'fund';
    case Certificate = 'certificate';
    case Deposit = 'deposit';
    case RealEstate = 'real_estate';
    case Gold = 'gold';
    case Silver = 'silver';
    case Crypto = 'crypto';
    case Business = 'business';
    case Bonds = 'bonds';
    case Other = 'other';
}
