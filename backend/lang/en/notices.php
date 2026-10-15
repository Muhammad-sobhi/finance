<?php

return [
    'categories' => ['food' => 'Food', 'transport' => 'Transport', 'bills' => 'Bills', 'shopping' => 'Shopping', 'health' => 'Health', 'education' => 'Education', 'entertainment' => 'Entertainment', 'family' => 'Family', 'work' => 'Work', 'zakat' => 'Zakat & charity', 'other' => 'Other'],
    'budget_near' => [
        'title' => 'Budget almost used: :category',
        'body' => 'You have spent :spent of your :limit :currency budget for :category this month (:pct%).',
    ],
    'budget_over' => [
        'title' => 'Over budget: :category',
        'body' => 'You have spent :spent :currency on :category this month — :over over your :limit budget.',
    ],
];
