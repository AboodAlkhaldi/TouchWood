<?php

declare(strict_types=1);

// أسماء إعدادات وحدة الوصول كما تعرضها شاشة الإعدادات (frontend.md 3.5، E4).
//
// تقع في قسم واحد للوحدة وإن حملت صلاحيتين (المالك، 2026-09-22): أرقام العملاء في المتجر عادية،
// وأرقام دخول الموظفين وأمنهم للإداريين وحدهم.
return [
    'module' => 'الوصول',

    // الموظفون: عامّة، وللإداريين وحدهم.
    'staff.password_min_length' => 'أقصر كلمة مرور',
    'staff.invitation_hours' => 'مدة صلاحية الدعوة (ساعات)',
    'staff.super_admin_invitation_hours' => 'مدة صلاحية دعوة المدير العام (ساعات)',
    'staff.email_change_hours' => 'مدة صلاحية رابط تغيير البريد (ساعات)',
    'staff.sms_code_length' => 'عدد خانات الرمز',
    'staff.sms_code_minutes' => 'مدة صلاحية الرمز (دقائق)',
    'staff.sms_resend_seconds' => 'الانتظار قبل إرسال رمز آخر (ثوانٍ)',
    'staff.sms_codes_per_hour' => 'عدد الرموز في الساعة',
    'staff.sms_code_attempts' => 'محاولات إدخال الرمز',
    'staff.lockout_attempts' => 'محاولات خاطئة قبل إيقاف الحساب مؤقتًا',
    'staff.lockout_minutes' => 'مدة الإيقاف المؤقت (دقائق)',
    'staff.ip_attempts' => 'محاولات خاطئة من عنوان واحد',
    'staff.ip_minutes' => 'مدة انتظار ذلك العنوان (دقائق)',
    'staff.session_idle_minutes' => 'الخروج بعد سكون (دقائق)',
    'staff.session_max_hours' => 'أطول مدة للجلسة (ساعات)',
    'staff.trusted_browser_days' => 'مدة تذكّر المتصفح الموثوق (أيام)',
    'staff.password_reset_minutes' => 'مدة صلاحية رابط إعادة التعيين (دقائق)',
    'staff.password_reset_hourly_limit' => 'رسائل إعادة التعيين في الساعة',

    // العملاء: خاصة بكل متجر.
    'customer.password_min_length' => 'أقصر كلمة مرور',
    'customer.email_verification_hours' => 'مدة صلاحية رابط التحقق (ساعات)',
    'customer.sms_code_length' => 'عدد خانات الرمز',
    'customer.sms_code_minutes' => 'مدة صلاحية الرمز (دقائق)',
    'customer.sms_resend_seconds' => 'الانتظار قبل إرسال رمز آخر (ثوانٍ)',
    'customer.sms_codes_per_hour' => 'عدد الرموز في الساعة',
    'customer.sms_code_attempts' => 'محاولات إدخال الرمز',
    'customer.lockout_attempts' => 'محاولات خاطئة قبل إيقاف الحساب مؤقتًا',
    'customer.lockout_minutes' => 'مدة الإيقاف المؤقت (دقائق)',
    'customer.ip_attempts' => 'محاولات خاطئة من عنوان واحد',
    'customer.ip_minutes' => 'مدة انتظار ذلك العنوان (دقائق)',
    'customer.session_idle_minutes' => 'الخروج بعد سكون (دقائق)',
    'customer.remember_days' => 'مدة «أبقني مسجّلًا» (أيام)',
    'customer.password_reset_minutes' => 'مدة صلاحية رابط إعادة التعيين (دقائق)',
    'customer.password_reset_hourly_limit' => 'رسائل إعادة التعيين في الساعة',
    'customer.address_requests_per_hour' => 'تعديلات العناوين في الساعة',
    'customer.addresses_per_store' => 'عدد العناوين لكل متجر',
    'customer.terms_version' => 'إصدار الشروط المعمول به',
];
