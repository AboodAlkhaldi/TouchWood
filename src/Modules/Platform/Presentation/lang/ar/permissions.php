<?php

declare(strict_types=1);

// أسماء صلاحيات Platform في محرر الأدوار (PlatformPermissions).
return [
    'store' => [
        'create' => 'إنشاء المتاجر',
        'update' => 'تعديل المتاجر',
        'view' => 'عرض المتاجر',
        'switch' => 'تشغيل المتاجر وإيقافها',
    ],
    'currency' => [
        'create' => 'إنشاء العملات',
        'update' => 'تعديل العملات',
        'delete' => 'حذف العملات',
    ],
    'settings' => [
        'view' => 'عرض الإعدادات',
        'update' => 'تغيير الإعدادات',
    ],
    'media' => [
        'upload' => 'رفع الملفات',
        'update' => 'تعديل وصف الملفات',
        'delete' => 'حذف الملفات',
        'private' => [
            'view' => 'عرض الملفات الخاصة',
        ],
        'variants' => [
            'generate' => 'إنشاء مقاسات الصور',
        ],
    ],
    'audit' => [
        'view' => 'عرض سجل التدقيق',
    ],
    'jobs' => [
        'manage' => 'عرض المهام المتعثّرة وإعادة تشغيلها وحذفها',
    ],
];
