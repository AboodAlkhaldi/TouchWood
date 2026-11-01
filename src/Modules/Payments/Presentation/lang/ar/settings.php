<?php

declare(strict_types=1);

// إعدادات المدفوعات كما تسميها صفحة الإعدادات (PaymentsSettings، payments.md §5).
return [
    'module' => 'المدفوعات',

    'timers.window_minutes' => 'الوقت في صفحة البنك (بالدقائق)',
    'timers.wait_minutes' => 'انتظار رد البنك بعده (بالدقائق)',
    'timers.hold_minutes' => 'مدة الحجز لدفع بلا رد (بالدقائق)',
    'transfer.check_hours' => 'ساعات مراجعة التحويل البنكي',
];
