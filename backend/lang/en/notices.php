<?php

return [
    'categories' => ['food' => 'Food', 'transport' => 'Transport', 'bills' => 'Bills', 'shopping' => 'Shopping', 'health' => 'Health', 'education' => 'Education', 'entertainment' => 'Entertainment', 'family' => 'Family', 'work' => 'Work', 'zakat' => 'Zakat & charity', 'salary' => 'Salary', 'freelance' => 'Freelance', 'other_income' => 'Other income', 'fees' => 'Fees', 'other' => 'Other'],
    'unusual_expense' => [
        'title' => 'Unusual expense: :category',
        'body' => 'You just spent :amount :currency on :category — about :times× what it usually costs (:median).',
    ],
    'new_sign_in' => [
        'title' => 'New sign-in to your account',
        'body' => 'Someone signed in from :device (IP :ip) at :time. If this wasn\'t you, change your password now and sign out other devices.',
    ],
    'password_reset' => [
        'title' => 'Your password was changed',
        'body' => "The password of your account was just reset and every device was signed out. If this wasn't you, reset it again right away and turn on two-step verification.",
    ],
    'budget_near' => [
        'title' => 'Budget almost used: :category',
        'body' => 'You have spent :spent of your :limit :currency budget for :category this month (:pct%).',
    ],
    'budget_over' => [
        'title' => 'Over budget: :category',
        'body' => 'You have spent :spent :currency on :category this month — :over over your :limit budget.',
    ],
    'gameya_invite' => [
        'title' => 'Gameya invitation: :gameya',
        'body' => ':manager added you to the gameya “:gameya”. Open Gameyas in Dinar to accept and follow your turn and payments.',
    ],
    'gameya_reminder' => [
        'title' => 'Gameya payment reminder: :gameya',
        'body' => 'Round :round of “:gameya” (:amount :currency) is due on :date and isn\'t recorded yet.',
    ],
    'gameya_claim' => [
        'title' => 'A member recorded a payment: :gameya',
        'body' => ':member says they paid round :round of “:gameya”. Check it and confirm.',
    ],
    'autopay_short' => [
        'title' => 'Auto-pay skipped: :item',
        'body' => 'Couldn\'t pay :item (:amount :item_currency) from :account — only :available :currency there, :shortfall short. Top it up or pay it from another account; it will be tried again tomorrow.',
    ],
];
