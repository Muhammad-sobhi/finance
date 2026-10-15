<?php

return [
    'title' => [
        'before' => 'Coming up',
        'due' => 'Due today',
        'overdue' => 'Overdue',
    ],
    'subject' => [
        'bill' => ':name',
        'subscription' => ':name renewal',
        'owed_to_me' => ':name owes you',
        'i_owe' => 'You owe :name',
        'credit_card' => ':name card payment',
        'maturity' => ':name matures',
    ],
    'when' => [
        'before' => 'in :days days',
        'due' => 'today',
        'overdue' => ':days days late',
    ],
    'body' => ':subject — :amount :currency, :when',
    'open' => 'Open Treasury',
];
