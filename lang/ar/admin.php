<?php

declare(strict_types=1);

// كلمات إطار لوحة الإدارة نفسه، وهو ما يحيط بكل شاشة ولا يخص وحدة بعينها. أما شاشات الوحدة
// فكلماتها في ملفات الوحدة.
return [
    'panel' => 'لوحة الإدارة',
    // The sidebar's own controls, read aloud in the page's language (shadcn writes them in English).
    'sidebar_toggle' => 'إظهار الشريط الجانبي أو إخفاؤه',
    // Said after a menu entry's name, for a screen reader on the rail of icons (owner's #2, 2026-10-02);
    // the pause before it is the language's own comma.
    'menu_waiting' => '، :count بانتظارك',
    'open_menu' => 'فتح القائمة',
    'close_menu' => 'إغلاق القائمة',
    'super_admin' => 'مدير عام',
    'coming_soon' => 'قريبًا',
    'coming_soon_title' => 'قريبًا',
    'coming_soon_subtitle' => 'لم تُبنَ بعد.',
    'coming_soon_body' => 'هذه الشاشة التالية في قائمة البناء.',

    'theme' => [
        // اسم المفتاح نفسه، يُقرأ بصوت عالٍ.
        'label' => 'المظهر',
        'system' => 'النظام',
        'light' => 'فاتح',
        'dark' => 'داكن',
    ],

    // A screen's own store filter, Home's switcher and the View Store menu (frontend.md §2.2; the
    // owner, 2026-10-06, access.md amendment 64).
    'store' => [
        'label' => 'المتجر',
        'stores' => 'المتاجر',
        'all' => 'كل المتاجر',
        // The mark on an off store, which only a Super Admin is offered (access.md amendment 58(a)).
        'off' => 'متوقف',
        // The same mark inside a select's option, where a badge cannot go.
        'option_off' => ':store (متوقف)',
    ],

    'home' => [
        'title' => 'الرئيسية',
        'subtitle' => 'لوحة الإدارة.',
        'empty' => 'ما يمكنك فتحه موجود في القائمة.',
        'empty_title' => 'لا شيء هنا بعد',
        // Above the rows of what waits (frontend.md E7; owner, 2026-10-03): Geist's Note, a label
        // and one sentence; each row then opens its screen and shows its count.
        'waiting_label' => 'بانتظارك',
        'waiting_note' => 'هناك ما يحتاج إليك.',
    ],

    // The staff view (access.md §1.11): the panel's way to the shop, and the shop's way back. The
    // shop reads these too, as it ships this file (StorefrontArea::WORDS).
    'staff_view' => [
        'open' => 'عرض المتجر',
        'line' => 'عرض الموظف: ترى هذا المتجر كما يراه الزائر، ولا يمكنك الطلب.',
        'back' => 'العودة إلى لوحة الإدارة',
        'leave' => 'إنهاء عرض الموظف',
    ],

    // كتلة الشخص أسفل القائمة الجانبية: حسابه الخاص، وطريق الخروج. وهي من الإطار لا من شاشات
    // وحدة بعينها.
    'account_settings' => 'الحساب والإعدادات',
    // The person menu's way out, carried by every panel page with the rest of this file.
    'sign_out' => 'تسجيل الخروج',

    // يمكن للمكوّنات المشتركة أن تقرأ كلمات الإطار، لأن كل صفحة إدارية تحمل هذا الملف.
    'close' => 'إغلاق',
];
