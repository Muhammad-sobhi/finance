<?php

return [
    'categories' => ['food' => 'طعام', 'transport' => 'مواصلات', 'bills' => 'فواتير', 'shopping' => 'تسوق', 'health' => 'صحة', 'education' => 'تعليم', 'entertainment' => 'ترفيه', 'family' => 'عائلة', 'work' => 'عمل', 'zakat' => 'زكاة وصدقات', 'salary' => 'راتب', 'freelance' => 'عمل حر', 'other_income' => 'دخل آخر', 'fees' => 'رسوم', 'other' => 'أخرى'],
    'unusual_expense' => [
        'title' => 'مصروف غير معتاد: :category',
        'body' => 'صرفت الآن :amount :currency على :category — حوالي :times أضعاف المعتاد (:median).',
    ],
    'new_sign_in' => [
        'title' => 'تسجيل دخول جديد إلى حسابك',
        'body' => 'تم تسجيل الدخول من :device (عنوان :ip) في :time. إن لم تكن أنت فغيّر كلمة المرور الآن وسجّل الخروج من الأجهزة الأخرى.',
    ],
    'password_reset' => [
        'title' => 'تم تغيير كلمة المرور',
        'body' => 'تم الآن تغيير كلمة مرور حسابك وتسجيل الخروج من كل الأجهزة. إن لم تكن أنت فغيّرها فورًا وفعّل التحقق بخطوتين.',
    ],
    'budget_near' => [
        'title' => 'الميزانية قاربت على النفاد: :category',
        'body' => 'صرفت :spent من ميزانية :limit :currency لبند :category هذا الشهر (:pct%).',
    ],
    'budget_over' => [
        'title' => 'تجاوزت الميزانية: :category',
        'body' => 'صرفت :spent :currency على :category هذا الشهر — بزيادة :over عن ميزانية :limit.',
    ],
    'gameya_invite' => [
        'title' => 'دعوة لجمعية: :gameya',
        'body' => ':manager أضافك إلى جمعية «:gameya». افتح الجمعيات في دينار لقبول الدعوة ومتابعة دورك ودفعاتك.',
    ],
    'gameya_reminder' => [
        'title' => 'تذكير بقسط الجمعية: :gameya',
        'body' => 'قسط الدورة :round في جمعية «:gameya» (:amount :currency) موعده :date ولم يُسجَّل بعد.',
    ],
    'gameya_claim' => [
        'title' => 'عضو سجّل دفعة: :gameya',
        'body' => ':member قال إنه دفع قسط الدورة :round في جمعية «:gameya». راجعها وأكّدها.',
    ],
    'autopay_short' => [
        'title' => 'لم يتم الدفع التلقائي: :item',
        'body' => 'تعذّر دفع :item (:amount :item_currency) من :account — المتاح :available :currency فقط، والناقص :shortfall. اشحن الحساب أو ادفعها من حساب آخر؛ ستُعاد المحاولة غدًا.',
    ],
];
