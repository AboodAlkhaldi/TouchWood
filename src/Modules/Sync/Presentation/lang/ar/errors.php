<?php

declare(strict_types=1);

// رسائل أخطاء وحدة المزامنة حسب نوع الخطأ (sync.{key})، وأسماء الحقول التي تشير إليها.
return [
    'connection_failed' => [
        'title' => 'تعذّرت القراءة من المزوّد',
        'detail' => 'لم يُحفظ شيء. السبب: :reason',
    ],
    'already_wired' => [
        'title' => 'مربوط بالفعل',
        'detail' => 'هذا المتجر مربوط بمزوّد بالفعل. غيّر اتصاله بدلًا من ذلك.',
    ],
    'not_wired' => [
        'title' => 'غير مربوط',
        'detail' => 'هذا المتجر غير مربوط بمزوّد.',
    ],
    'entry_not_found' => [
        'title' => 'غير موجود',
        'detail' => 'لا يوجد هذا البند في تقرير المزامنة.',
    ],
    'invalid_sync_input' => [
        'title' => 'تحقّق من البيانات',
        'detail' => 'قيمة :attribute غير صالحة.',
    ],

    'fields' => [
        'address' => 'العنوان',
        'key' => 'المفتاح',
        'provider' => 'المزوّد',
        'secret' => 'سرّ الإشعارات',
        'store' => 'المتجر',
    ],
];
