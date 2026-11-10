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
    // PARKED for the owner (plan P8): the English is now "Library"; its Arabic waits between المكتبة and
    // مكتبة ملفات النظام, so today's word stays until the owner answers.
    'media' => 'مكتبة الوسائط',
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
