<?php

declare(strict_types=1);

// شاشة سجل التدقيق (frontend.md 3.5، E6).
return [
    'title' => 'سجل التدقيق',
    'subtitle' => 'من غيّر ماذا، ومتى. يُحفظ إلى الأبد، ولا يُعدَّل.',

    'when' => 'متى',
    'who' => 'من',
    'what' => 'ماذا',
    'subject' => 'على',
    'source' => 'كيف',
    'store' => 'المتجر',
    'ip' => 'من عنوان',
    'no_store' => 'النظام كله',
    // سجلّ ملف خاص، لمن لا يُسمح له برؤية الملفات الخاصة (b2b.md، التعديل 8(ج)).
    'private_file' => 'ملف خاص — أيّ ملف هو، وما الذي تغيّر، يظهران لمن يُسمح له برؤية الملفات الخاصة',

    'source_web' => 'لوحة الإدارة',
    'source_integration' => 'تكامل',
    'source_console' => 'أمر من الطرفية',
    'source_job' => 'مهمة في الخلفية',
    'source_import' => 'استيراد',

    'actor_staff' => 'موظف',
    'actor_customer' => 'عميل',
    'actor_guest' => 'زائر',
    'actor_integration' => 'تكامل',
    'actor_system' => 'النظام',
    'requested_by' => 'بطلب من :who',

    'changes' => 'ما تغيّر',
    'personal' => 'تغيّر',
    'from_to' => 'من :from إلى :to',
    'nothing_recorded' => 'لم يُسجَّل معه شيء.',

    'filters' => 'التصفية',
    'from' => 'من',
    'until' => 'إلى',
    'actor' => 'من (المعرّف)',
    'action' => 'ماذا',
    'any' => 'أي شيء',
    'apply' => 'تطبيق التصفية',
    'clear' => 'مسح التصفية',
    'more' => 'عرض المزيد',
    'none' => 'لا شيء هنا بعد.',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'none_title' => 'لا سجلات',
];
