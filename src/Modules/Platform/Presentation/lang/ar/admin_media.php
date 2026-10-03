<?php

declare(strict_types=1);

// مكتبة الوسائط (frontend.md 3.5، E5).
return [
    'title' => 'مكتبة الوسائط',
    'subtitle' => 'كل ملف في النظام، الأحدث أولًا.',

    'table' => 'جدول',
    'grid' => 'شبكة',
    'file' => 'الملف',
    'type' => 'النوع',
    'size' => 'الحجم',
    'used_in' => 'مستخدم في',
    'uploaded' => 'رُفع',
    'not_used' => 'غير مستخدم',
    'dimensions' => ':width في :height',

    'variants_pending' => 'قيد المعالجة',
    'variants_ready' => 'جاهزة',
    'variants_failed' => 'فشلت المعالجة',
    'retry' => 'إعادة المعالجة',
    'retrying' => 'بدأت إعادة المعالجة',

    'upload' => 'رفع ملف',
    'uploaded_ok' => 'تم رفع الملف',
    'visibility' => 'من يراه',
    'visibility_public' => 'كل من لديه الرابط',
    'visibility_private' => 'لوحة الإدارة وحدها',
    'no_file' => 'تعذّر رفع الملف. قد يكون أكبر مما يقبله الخادم.',

    'alt' => 'الوصف',
    'alt_hint' => 'يُقرأ لمن لا يرى الصورة.',
    'alt_ar' => 'الوصف بالعربية',
    'alt_en' => 'الوصف بالإنجليزية',
    'describe' => 'تعديل الوصف',
    'described' => 'تم حفظ الوصف',

    'delete' => 'حذف الملف',
    'deleted' => 'تم حذف الملف',
    'delete_blocked' => 'يُحتفظ بهذا الملف بسبب مواضع استخدامه، ولا يمكن حذفه.',
    'delete_confirm' => 'يُستخدم هذا الملف في :count مواضع، وستفقده.',
    'delete_confirm_unused' => 'لا شيء يستخدم هذا الملف.',

    'save' => 'حفظ الوصف',
    'cancel' => 'إلغاء',
    'more' => 'عرض المزيد',
    'none' => 'لا ملف بعد.',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'none_title' => 'لا ملفات بعد',
    'delete_title' => 'حذف الملف',
    'upload_needs_file' => 'اختر ملفًا لرفعه أولًا.',
];
