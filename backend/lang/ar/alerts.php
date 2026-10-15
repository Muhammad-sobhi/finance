<?php

return [
    'title' => [
        'before' => 'موعد قريب',
        'due' => 'مستحق اليوم',
        'overdue' => 'متأخر',
    ],
    'subject' => [
        'bill' => ':name',
        'subscription' => 'تجديد :name',
        'owed_to_me' => 'دين لك على :name',
        'i_owe' => 'دين عليك لـ :name',
        'credit_card' => 'سداد بطاقة :name',
        'income' => 'دخل متوقع: :name',
        'project' => 'دفعة من :name',
        'maturity' => 'استحقاق :name',
    ],
    'when' => [
        'before' => 'بعد :days يوم',
        'due' => 'اليوم',
        'overdue' => 'متأخر :days يوم',
    ],
    'body' => ':subject — :amount :currency، :when',
    'open' => 'افتح خزينتي',
];
