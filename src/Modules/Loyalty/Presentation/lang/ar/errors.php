<?php

declare(strict_types=1);

// رسائل أخطاء وحدة النقاط بحسب نوع الخطأ (loyalty.{key})، وأسماء الحقول التي تشير إليها
// (المواصفة §7).
return [
    'points_programme_off' => [
        'title' => 'النقاط متوقفة',
        'detail' => 'لا يمكن استخدام النقاط في هذا المتجر الآن.',
    ],
    'redemption_changed' => [
        'title' => 'تغيّر خصم نقاطك',
        'detail' => 'تغيّر خصم نقاطك منذ أن احتسبته صفحة الدفع. راجعه وأرسل الطلب مجددًا.',
    ],
    'deduction_too_large' => [
        'title' => 'النقاط غير كافية',
        'detail' => 'لا يملك العميل هذا العدد من النقاط. اخصم عددًا أقل.',
    ],
    'points_account_not_found' => [
        'title' => 'لا توجد نقاط',
        'detail' => 'لم يُعثر على نقاط لهذا العميل في هذا المتجر.',
    ],
    'order_not_settleable' => [
        'title' => 'تعذّرت تسوية الطلب',
        'detail' => 'لا يمكن تسوية نقاط هذا الطلب بهذه الطريقة.',
    ],
    'invalid_points_attribute' => [
        'title' => 'تحقّق من بيانات النقاط',
        'detail' => 'قيمة :attribute غير صالحة. تحقّق منها وحاول مجددًا.',
    ],

    'fields' => [
        'points' => 'عدد النقاط',
        'reason' => 'السبب',
        'returned_amounts' => 'المبالغ المُرجعة',
        'currency' => 'العملة',
        'store' => 'المتجر',
        'permission' => 'الصلاحية',
    ],
];
