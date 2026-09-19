<?php

declare(strict_types=1);

// رسائل أخطاء Access، حسب نوع الخطأ (access.{key}).
return [
    'invalid_access_attribute' => [
        'title' => 'بيانات غير صالحة',
        'detail' => 'قيمة :attribute غير صالحة.',
    ],
    'role_not_found' => [
        'title' => 'الدور غير موجود',
        'detail' => 'لا يوجد دور بهذا المعرّف.',
    ],
    'staff_not_found' => [
        'title' => 'الموظف غير موجود',
        'detail' => 'لا يوجد موظف بهذا المعرّف.',
    ],
    'unknown_permission' => [
        'title' => 'صلاحية غير معروفة',
        'detail' => '":permission" ليست صلاحية يمكن إضافتها إلى دور.',
    ],
    'reserved_permission' => [
        'title' => 'للمشرفين العامين فقط',
        'detail' => '":permission" خاصة بالمشرفين العامين ولا يمكن إضافتها إلى دور.',
    ],
    'admin_only_permission' => [
        'title' => 'لأدوار المشرفين فقط',
        'detail' => '":permission" صلاحية إدارية ولا تضاف إلا إلى دور مشرف.',
    ],
    'permission_escalation' => [
        'title' => 'أكثر مما تملك',
        'detail' => 'لا يمكنك منح ":permission" هنا: لا تملكها في كل المتاجر التي ستصل إليها.',
    ],
    'role_name_taken' => [
        'title' => 'الاسم مستخدم',
        'detail' => 'يوجد دور محفوظ آخر باسم ":name".',
    ],
    'role_in_use' => [
        'title' => 'الدور مستخدم',
        'detail' => 'يحمل هذا الدور :count موظف. اختر لهم دورًا بديلًا أولًا.',
    ],
    'staff_not_editable' => [
        'title' => 'لا يمكن تعديل هذا الموظف هنا',
        'detail' => 'المشرفون العامون يُدارون من الخادم فقط، والمشرفون لا يديرهم إلا مشرف عام، ولا أحد يعدّل صلاحياته بنفسه.',
    ],
    'super_admin_only' => [
        'title' => 'للمشرفين العامين فقط',
        'detail' => 'لا ينشئ دور المشرف أو يعدّله أو يمنحه إلا مشرف عام.',
    ],
];
