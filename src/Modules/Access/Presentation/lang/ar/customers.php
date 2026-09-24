<?php

declare(strict_types=1);

// شاشات العملاء في اللوحة (المرحلة ٢ب، الخطوة ٤، frontend.md §3.7، G1 وG2).
return [
    'title' => 'العملاء',
    'subtitle' => 'من يتسوّقون من متاجرك.',

    'search' => 'بحث',
    'search_hint' => 'اسم أو بريد إلكتروني أو رقم جوال.',
    'filters' => 'التصفية',
    'type' => 'النوع',
    'status' => 'الحالة',
    'any' => 'الكل',
    'apply' => 'تطبيق',
    'clear' => 'مسح',
    'none' => 'لا عميل يطابق ذلك.',
    'total' => ':count في المجموع',
    'previous' => 'السابق',
    'next' => 'التالي',

    'name' => 'الاسم',
    'email' => 'البريد الإلكتروني',
    'phone' => 'الجوال',
    'home_store' => 'المتجر',
    'registered' => 'سجّل في',
    'verified' => 'مؤكَّد',
    'email_verified' => 'البريد مؤكَّد',
    'phone_verified' => 'الجوال مؤكَّد',
    'not_verified' => 'غير مؤكَّد بعد',
    'no_phone' => 'لا يوجد رقم',

    'account_type' => [
        'INDIVIDUAL' => 'فرد',
        'COMPANY' => 'شركة',
    ],
    'account_status' => [
        'ACTIVE' => 'نشط',
        'BLOCKED' => 'محظور',
    ],
    'deletion_pending' => 'يُغلق في :date',
    'anonymized' => 'مُجهَّل',

    // G2.
    'addresses' => 'العناوين',
    'no_addresses' => 'لا يوجد عنوان محفوظ.',
    'communication_language' => 'نراسله بـ',
    'profile_is_theirs' => 'بيانات العميل له وحده، ولا شيء هنا يغيّرها.',
    'actions' => 'الإجراءات',
    'reason' => 'السبب',
    'reason_hint' => 'يُحفظ مع التغيير لمن يسأل عنه لاحقًا.',
    'block' => 'حظر الحساب',
    'block_body' => 'لن يستطيع تسجيل الدخول. ولا يُخبَر بأن الحساب محظور إلا من أدخل كلمة المرور الصحيحة، فلا يتعلّم الغريب شيئًا من المحاولة.',
    'unblock' => 'رفع الحظر',
    'unblock_body' => 'يستطيع تسجيل الدخول مجددًا.',
    'start_deletion' => 'إغلاق الحساب بناءً على طلبه',
    'start_deletion_body' => 'المدة نفسها التي يبدأها العميل بنفسه، :count يومًا: يُقفل في الحال، ويُجهَّل في ذلك التاريخ، وتسجيل الدخول قبله يلغي الإغلاق.',
    'cancel_deletion' => 'إيقاف الإغلاق',
    'cancel_deletion_body' => 'لمن لا يستطيع تسجيل الدخول ليوقفه بنفسه، وهي الطريقة الأخرى الوحيدة.',
    'confirm' => 'تأكيد',
    'cancel' => 'إلغاء',

    'blocked' => 'تم حظر الحساب.',
    'unblocked' => 'عاد الحساب نشطًا.',
    'deletion_started' => 'الحساب في طور الإغلاق.',
    'deletion_cancelled' => 'أُوقف الإغلاق.',
];
