<?php

declare(strict_types=1);

// The business area an action is shown under, in the role editor and the admin menu
// (stage 2b, P2). One list serves both (owner, 2026-09-22).
//
// Round 3 #10 (owner, 2026-11-10: "keep"): the design's shorter words, with the article as every area
// here has it (plan pick K3).

return [
    'staff_and_permissions' => 'طاقم العمل',
    'customers' => 'العملاء',
    // The panel menu's Reports section (ops.md §4.15, the menu): a menu section, not an area of the role editor.
    'reports' => 'التقارير',
    'store_settings' => 'الإعدادات',
    // The owner, 2026-11-10 (P8): "Library" is المكتبة, in the menu and the role editor alike. The media
    // page keeps its own title (platform::admin_media.title).
    'media' => 'المكتبة',
    'audit' => 'سجل التدقيق',
    'system' => 'النظام',
    'catalog' => 'المنتجات',
    'pricing' => 'الأسعار والحملات',
    'orders' => 'المبيعات',
    'shipping' => 'الشحن',
    'payments' => 'المدفوعات',
    'companies' => 'اعتماد الشركات',
    'points' => 'النقاط',
    'sync' => 'المزامنة',
    'marketing' => 'التسويق',
    'support' => 'الدعم',
    'messages_and_reports' => 'الرسائل والتقارير',
    'content' => 'المحتوى',
];
