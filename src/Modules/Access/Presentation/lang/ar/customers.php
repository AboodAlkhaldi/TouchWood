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
    'apply' => 'تطبيق التصفية',
    'clear' => 'مسح التصفية',
    // Geist's Empty State: a filtered list that finds nobody suggests widening or clearing the
    // filters; a list with no customers at all says when they appear (shadcn rebuild).
    'none' => 'وسّع التصفية أو امسحها لترى عملاء أكثر.',
    'empty_title' => 'لا عملاء بعد',
    'empty' => 'يظهر العملاء هنا حين يسجّلون في أحد متاجرك.',
    'total' => ':count في المجموع',
    'previous' => 'السابق',
    'next' => 'التالي',

    'name' => 'الاسم',
    'email' => 'البريد الإلكتروني',
    'phone' => 'الجوال',
    'home_store' => 'المتجر',
    'registered' => 'سجّل في',
    'email_verified' => 'البريد مؤكَّد',
    'phone_verified' => 'الجوال مؤكَّد',
    // One badge per column, its word the state (Geist's Badge: never colour alone).
    'confirmed' => 'مؤكَّد',
    'not_confirmed' => 'غير مؤكَّد',
    'no_phone' => 'لا يوجد رقم',

    'account_type' => [
        'INDIVIDUAL' => 'فرد',
        'COMPANY' => 'شركة',
    ],
    'account_status' => [
        'ACTIVE' => 'نشط',
        'BLOCKED' => 'محظور',
    ],
    // A badge is a word, two at most (Geist); the date is said beside it.
    'closing' => 'قيد الإغلاق',
    'closing_on' => 'يُغلق في',
    'anonymized' => 'مُجهَّل',

    // G2.
    'addresses' => 'العناوين',
    'no_addresses' => 'تظهر هنا العناوين التي يحفظها العميل.',
    'communication_language' => 'لغة المراسلة',
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
    'cancel_deletion' => 'إيقاف إغلاق الحساب',
    'cancel_deletion_body' => 'لمن لا يستطيع تسجيل الدخول ليوقفه بنفسه، وهي الطريقة الأخرى الوحيدة.',
    'confirm' => 'تأكيد',
    'cancel' => 'إلغاء',

    'blocked' => 'تم حظر الحساب',
    'unblocked' => 'تم رفع الحظر عن الحساب',
    'deletion_started' => 'تم إغلاق الحساب',
    'deletion_cancelled' => 'تم إيقاف إغلاق الحساب',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'none_title' => 'لا عملاء يطابقون التصفية',
    'no_addresses_title' => 'لا عناوين',
];
