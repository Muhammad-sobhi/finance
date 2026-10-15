<?php

namespace App\Enums;

enum AccountType: string
{
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case InstaPay = 'instapay';
    case VodafoneCash = 'vodafone_cash';
    case EtisalatCash = 'etisalat_cash';
    case OrangeCash = 'orange_cash';
    case WePay = 'we_pay';
    case Cash = 'cash';
    case Bank = 'bank';
    case Savings = 'savings';

    /** Money that can be spent today (excludes savings and credit). */
    public function isLiquid(): bool
    {
        return ! in_array($this, [self::Savings, self::CreditCard], true);
    }
}
