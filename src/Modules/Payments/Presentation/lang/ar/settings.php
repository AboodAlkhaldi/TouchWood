<?php

declare(strict_types=1);

// إعدادات المدفوعات كما تسميها صفحة الإعدادات (PaymentsSettings، payments.md §5).
return [
    'module' => 'المدفوعات',

    'timers.window_minutes' => 'الوقت في صفحة البنك (بالدقائق)',
    'timers.wait_minutes' => 'انتظار رد البنك بعده (بالدقائق)',
    'timers.hold_minutes' => 'مدة الحجز لدفع بلا رد (بالدقائق)',
    'transfer.check_hours' => 'ساعات مراجعة التحويل البنكي',
    'bank.iban' => 'رقم الآيبان الذي تحوّل إليه الشركات',
    'bank.name' => 'اسم البنك',
    'bank.holder' => 'اسم صاحب الحساب',

    // سطر القسم (PaymentsSettingsLine، §1.2، gap 8.8): الجزآن يفصل بينهما " · ".
    'line.online.off' => 'الدفع الإلكتروني: متوقف — يدفع الأفراد عبر الموظفين حتى إعداد بوابة الدفع',
    'line.online.test' => 'الدفع الإلكتروني: وضع الاختبار — لا تُؤخذ أموال حقيقية',
    'line.online.on' => 'الدفع الإلكتروني: مفعّل',
    'line.online.key_rejected' => 'الدفع الإلكتروني: متوقف — رفضت MyFatoorah المفتاح؛ يدفع الأفراد عبر الموظفين حتى حفظ مفتاح جديد',
    'line.bank_transfer.on' => 'التحويل البنكي: مفعّل',
    'line.bank_transfer.off' => 'التحويل البنكي: متوقف مؤقتًا — عبّئ الثلاثة كلها لتفعيله.',
];
