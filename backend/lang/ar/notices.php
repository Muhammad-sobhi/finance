<?php

return [
    'categories' => ['food' => 'طعام', 'transport' => 'مواصلات', 'bills' => 'فواتير', 'shopping' => 'تسوق', 'health' => 'صحة', 'education' => 'تعليم', 'entertainment' => 'ترفيه', 'family' => 'عائلة', 'work' => 'عمل', 'zakat' => 'زكاة وصدقات', 'salary' => 'راتب', 'freelance' => 'عمل حر', 'other_income' => 'دخل آخر', 'other' => 'أخرى'],
    'unusual_expense' => [
        'title' => 'مصروف غير معتاد: :category',
        'body' => 'صرفت الآن :amount :currency على :category — حوالي :times أضعاف المعتاد (:median).',
    ],
    'budget_near' => [
        'title' => 'الميزانية قاربت على النفاد: :category',
        'body' => 'صرفت :spent من ميزانية :limit :currency لبند :category هذا الشهر (:pct%).',
    ],
    'budget_over' => [
        'title' => 'تجاوزت الميزانية: :category',
        'body' => 'صرفت :spent :currency على :category هذا الشهر — بزيادة :over عن ميزانية :limit.',
    ],
];
