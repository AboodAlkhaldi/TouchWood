<?php

declare(strict_types=1);

// ما تعرضه صفحات الدخول والحساب بعد كل خطوة، في لوحة الإدارة وفي المتجر. الشاشات نفسها (المرحلة
// ٢ب، الخطوة ١) تقرأ من هذا الملف: لا توجد ملفات ترجمة منفصلة للواجهة، فتُكتب العبارة مرة واحدة
// وتُستعمل في الجهتين.
return [
    'registered' => 'تم إنشاء الحساب؛ أكّد عنوانك من الرسالة التي أرسلناها',
    'verification_sent' => 'تم إرسال رابط التأكيد',
    'email_verified' => 'تم تأكيد بريدك الإلكتروني',
    'code_sent' => 'تم إرسال الرمز إلى جوالك',
    'reset_link_sent' => 'تم إرسال رابط إعادة التعيين، إن كان هذا البريد لحساب لدينا',
    'password_reset' => 'تم تغيير كلمة المرور',
    'password_changed' => 'تم تغيير كلمة المرور، وسُجّل الخروج من كل جلسة أخرى',
    'email_changed' => 'تم تغيير بريدك الإلكتروني',
    'invitation_accepted' => 'تم إنشاء حسابك',
    'signed_out' => 'تم تسجيل خروجك',

    // الشاشات (A1–A9).
    'sign_in' => 'تسجيل الدخول',
    'sign_in_subtitle' => 'لوحة الإدارة.',
    'sign_out' => 'تسجيل الخروج',
    // The shopper's menu in the shop's header (owner's #4, 2026-10-02).
    'my_account' => 'حسابي',
    'email' => 'بريد العمل',
    'password' => 'كلمة المرور',
    'show_password' => 'إظهار كلمة المرور',
    'hide_password' => 'إخفاء كلمة المرور',
    'forgot_password' => 'إعادة تعيين كلمة المرور',
    'back_to_sign_in' => 'العودة لتسجيل الدخول',

    'phone_title' => 'رقم جوالك',
    'phone_subtitle' => 'سنرسل إليه رمزًا لإتمام الدخول.',
    'phone' => 'رقم الجوال',
    'phone_hint' => 'مع رمز الدولة، مثل ‎+966501234567.',
    'send_code' => 'أرسل الرمز',

    'code_title' => 'أدخل الرمز',
    'code_sent_to' => 'أُرسل الرمز إلى :phone.',
    // The code field's one label: shadcn's InputOTP is one input, not a box per digit.
    'code_label' => 'رمز الرسالة',
    'trust_browser' => 'الوثوق بهذا المتصفح لمدة :days يومًا',
    'confirm' => 'التحقق من الرمز',
    'resend' => 'إرسال رمز آخر',
    'resend_in' => 'رمز آخر بعد :seconds ثانية',

    'forgot_title' => 'اختيار كلمة مرور جديدة',
    'forgot_subtitle' => 'سنرسل إليك رابطًا على بريدك.',
    'send_link' => 'أرسل الرابط',

    'reset_title' => 'كلمة المرور الجديدة',
    'new_password' => 'كلمة المرور الجديدة',
    'confirm_password' => 'أعد كتابة كلمة المرور',
    'password_rule' => 'لا تقل عن :count محرفًا.',
    'save_password' => 'حفظ كلمة المرور',

    'invitation_title' => 'إعداد حسابك',
    'invitation_subtitle' => 'أهلًا بك يا :name.',
    'accept_invitation' => 'إنشاء حسابي',

    'email_change_title' => 'تأكيد بريدك الجديد',
    'email_change_subtitle' => 'لا يتغيّر شيء حتى تضغط الزر.',

    /*
    | شاشات المتجر نفسها (F3–F6). مفاتيح مستقلة عن مفاتيح اللوحة لأن العبارات تختلف: للمتسوّق بريد
    | إلكتروني لا بريد عمل، وهو يدخل إلى حسابه هو لا إلى لوحة إدارة.
    */
    'shop_sign_in_subtitle' => 'حسابك وعناوينك وطلباتك.',
    'customer_email' => 'البريد الإلكتروني',
    'first_name' => 'الاسم الأول',
    'last_name' => 'اسم العائلة',
    'remember_me' => 'أبقني مسجّل الدخول :days يومًا',
    'no_account' => 'ليس لديك حساب؟',
    'create_account' => 'إنشاء حساب',
    'have_account' => 'لديك حساب بالفعل؟',

    'register_title' => 'إنشاء حساب',
    'register_subtitle' => 'حساب واحد لكل الدول التي نبيع فيها.',
    'account_type' => 'نوع الحساب',
    'account_type_individual' => 'لي',
    'account_type_individual_hint' => 'تسوّق بصفة شخصية.',
    'account_type_company' => 'لشركتي',
    'account_type_company_hint' => 'بيانات الشركة ومستنداتها تأتي بعد ذلك.',
    'account_type_permanent' => 'لا يمكن تغيير هذا الاختيار لاحقًا.',
    'account_type_required' => 'اختر نوع الحساب.',
    'terms_accept' => 'أوافق على شروط البيع وسياسة الخصوصية.',

    'verify_title' => 'تأكيد بريدك الإلكتروني',
    'verify_subtitle' => 'أرسلنا الرابط إلى هذا العنوان:',
    'verify_link_hours' => 'الرابط صالح :count ساعة.',
    'verify_what_next' => 'إلى أن تؤكّده يمكنك التصفّح وملء السلة، لكن لا يمكنك الطلب.',
    'verify_resend' => 'إعادة إرسال الرابط',
    'verify_pending' => 'أكّد بريدك الإلكتروني',
    'passwords_differ' => 'كلمتا المرور غير متطابقتين.',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'confirm_new_email' => 'تأكيد البريد الجديد',
    'resend_wait_reason' => 'يمكن إرسال رمز جديد بعد انتهاء الانتظار.',
];
