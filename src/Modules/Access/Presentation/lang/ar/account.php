<?php

declare(strict_types=1);

// كلمات شاشة «الحساب والإعدادات» الخاصة بالموظف نفسه. أما نصوص الرفض فمكانها access::errors،
// ولا تكتب الشاشة صياغة خاصة بها لأيٍّ منها.
return [
    'title' => 'الحساب والإعدادات',
    'subtitle' => 'بياناتك، وطريقة دخولك، وما نُخبرك به.',

    'tab' => [
        'account' => 'الحساب',
        'security' => 'الأمان',
        'sessions' => 'الجلسات',
        'notifications' => 'التنبيهات',
    ],

    'save' => 'حفظ التغييرات',
    'saved' => 'تم حفظ التغييرات',
    'cancel' => 'إلغاء',

    // ب1 — الصورة والملف الشخصي.
    'picture' => 'الصورة',
    'picture_hint' => 'ليعرفك من تعمل معهم في القوائم.',
    'choose_picture' => 'اختيار صورة',
    'replace_picture' => 'استبدال الصورة',
    'remove_picture' => 'إزالة الصورة',
    'picture_removed' => 'ستُزال عند الحفظ.',
    'picture_chosen' => 'اخترت: :name، وتظهر عند الحفظ.',
    'no_picture' => 'لا توجد صورة بعد.',

    'first_name' => 'الاسم الأول',
    'last_name' => 'اسم العائلة',
    'job_title' => 'المسمى الوظيفي',
    'date_of_birth' => 'تاريخ الميلاد',
    'country' => 'الدولة',
    // The country picker's search (Geist's Combobox, past two hundred countries).
    'country_search' => 'ابحث في الدول',
    'country_none' => 'لا دول تطابق «:query».',
    'address' => 'العنوان',
    'address_hint' => 'اختياري.',

    'communication_language' => 'لغة الرسائل والبريد',
    'communication_language_hint' => 'يصلك بريدنا ورموز الدخول بهذه اللغة، أيًّا كانت لغة عرض اللوحة.',
    'language' => [
        'ar' => 'العربية',
        'en' => 'الإنجليزية',
    ],

    // ب1 — البريد، وهو للقراءة فقط لغير المدير العام.
    'email' => 'بريد العمل',
    'email_locked' => 'اطلب من أحد المديرين تغييره.',
    'change_email' => 'تغيير بريد العمل…',
    // Geist's Note: a one- or two-word label, then one sentence (the batch B audit).
    'email_pending_label' => 'تغيير معلّق',
    'email_pending' => 'يصبح عنوانك :email حين يُستخدم الرابط المرسل إليه.',
    'email_dialog_title' => 'تغيير بريد العمل',
    'email_dialog_body' => 'يصل رابط إلى العنوان الجديد، ولا يتغير بريدك إلا عند فتح ذلك الرابط، فالعنوان المكتوب خطأً لا يغيّر شيئًا.',
    'new_email' => 'البريد الجديد',
    'email_change_sent' => 'تم إرسال الرابط إلى العنوان الجديد',

    // ب2 — الجوال، خلف كلمة المرور الحالية.
    'phone' => 'الجوال',
    'phone_hint' => 'إليه تصل رموز تسجيل الدخول.',
    'no_phone' => 'لا يوجد رقم مسجّل.',
    'change_phone' => 'تغيير رقم الجوال…',
    'phone_dialog_title' => 'تغيير رقم الجوال',
    'phone_dialog_body' => 'رموز دخولك تصل إلى هذا الرقم، لذلك نسألك كلمة المرور أولًا. ويظل رقمك الحالي عاملًا إلى أن تُدخل الرمز الذي نرسله إلى الرقم الجديد.',
    'new_phone' => 'الرقم الجديد',
    'new_phone_hint' => 'مع رمز الدولة، مثل ‎+966501234567‎.',
    'current_password' => 'كلمة المرور الحالية',
    'send_code' => 'إرسال الرمز',
    'phone_code_sent' => 'تم إرسال الرمز إلى الرقم الجديد',
    'phone_code' => 'الرمز الذي أرسلناه',
    'code_incomplete' => 'أدخل أرقام الرمز كلها (:count).',
    'confirm_phone' => 'تأكيد الرقم',
    'phone_changed' => 'تم تغيير رقمك، وسيطلب كل متصفح وثقت به رمزًا من جديد',

    // ب3 — كلمة المرور. لا يوجد زر للتحقق بخطوتين، والسبب في §2.7.
    'change_password' => 'تغيير كلمة المرور',
    'password_rule' => ':count أحرف على الأقل.',
    'new_password' => 'كلمة المرور الجديدة',
    'confirm_password' => 'أعد كتابة كلمة المرور الجديدة',
    'passwords_differ' => 'كلمتا المرور غير متطابقتين.',
    'password_note' => 'تغييرها يُسجّل الخروج من كل جلسة أخرى، ويجعل كل متصفح وثقت به يطلب رمزًا من جديد.',
    'two_factor_title' => 'تسجيل الدخول',
    'two_factor_body' => 'يسجّل كل موظف دخوله برمز يصل إلى جواله، ولا يمكن إيقاف ذلك.',

    // ب4 — مفاتيح التنبيهات، يُحفظ كل واحد منها فور تغييره.
    'notifications_hint' => 'يُحفظ كل مفتاح فور تغييره.',
    'by_email' => 'بريد',
    'in_panel' => 'في اللوحة',
    'topic' => [
        'NEW_ORDERS' => 'الطلبات الجديدة',
        'COMPANY_APPLICATIONS' => 'طلبات الشركات',
        'LOW_STOCK' => 'قرب نفاد المخزون',
        'CAMPAIGN_EXPIRY' => 'الحملات التي توشك على الانتهاء',
    ],

    /*
    | حساب العميل في المتجر (F7–F10). مفاتيح مستقلة لأن العبارات تختلف: للمتسوّق بريد إلكتروني لا
    | بريد عمل، ورقمه للتواصل بشأن الطلب لا لرمز الدخول، ولا توجد لوحة ولا متصفّح موثوق.
    */
    'shop_title' => 'حسابي',
    'shop_subtitle' => 'بياناتك، وطريقة دخولك، وأين نصل إليك.',
    'shop_tab' => [
        'profile' => 'البيانات',
        'security' => 'كلمة المرور',
        'phone' => 'الجوال',
        'addresses' => 'العناوين',
        'close' => 'إغلاق الحساب',
    ],

    'shop_email' => 'البريد الإلكتروني',
    'shop_email_locked' => 'لا يمكن تغيير بريدك الإلكتروني.',
    'shop_communication_language_hint' => 'تصلك رسائلنا بهذه اللغة، أيًّا كانت لغة عرض المتجر.',

    'account_kind' => 'نوع الحساب',
    'account_kind_locked' => 'اختير عند التسجيل ولا يمكن تغييره.',
    'home_store' => 'متجرك',
    'home_store_hint' => 'يمكنك التسوّق من أي دولة من دولنا، أيًّا كانت الدولة التي سجّلت فيها.',

    'shop_phone_hint' => 'حيث نصل إليك بشأن الطلب والتوصيل.',
    'shop_no_phone' => 'لا يوجد رقم بعد.',
    'shop_add_phone' => 'إضافة رقم',
    'shop_change_phone' => 'تغيير الرقم',
    'shop_phone_dialog_body' => 'نرسل رمزًا إلى الرقم الجديد، ويبقى رقمك الحالي عاملًا إلى أن يُدخل.',
    'shop_phone_changed' => 'تم تأكيد رقمك',
    'shop_password_note' => 'تغييرها يسجّل الخروج من كل متصفّح آخر أنت داخل منه.',

    'before_ordering' => 'الطلبات',
    'missing_email' => 'أكّد بريدك الإلكتروني لتتمكّن من الطلب.',
    'missing_phone' => 'أضف رقم جوال وأكّده لتتمكّن من الطلب.',
    'missing_both' => 'أكّد بريدك الإلكتروني ورقم جوالك لتتمكّن من الطلب.',

    // F9 — دفتر العناوين، وF10 — إغلاق الحساب.
    'addresses' => 'العناوين',
    'addresses_hint' => 'نوصّل إلى العنوان المعتاد في كل دولة ما لم تختر غيره.',
    'no_addresses' => 'أضف عنوانًا لتصلك الطلبات في هذه الدولة.',
    'no_format' => 'لا نوصّل إلى هذه الدولة بعد.',
    'address_full' => 'لديك أقصى ما تسمح به هذه الدولة من العناوين (:count)؛ احذف واحدًا لتضيف آخر.',
    'add_address' => 'إضافة عنوان',
    'edit_address' => 'تعديل العنوان',
    'delete_address' => 'حذف العنوان',
    'address_label' => 'اسم له',
    'address_label_hint' => 'لك وحدك، مثل المنزل أو العمل.',
    'recipient_name' => 'من يستلمه',
    'address_phone' => 'جوال للتوصيل',
    'address_phone_hint' => 'مع رمز الدولة، مثل ‎+966501234567.',
    'default_address' => 'وصّل هنا افتراضيًا',
    'is_default' => 'العنوان المعتاد',
    'make_default' => 'تعيين العنوان المعتاد',
    'address_incomplete' => 'هذه الدولة صارت تطلب ما لا يحمله هذا العنوان، فعدّله قبل أن تطلب.',
    'address_saved' => 'تم حفظ العنوان',
    'address_default_set' => 'تم تعيين العنوان المعتاد',
    'address_deleted' => 'تم حذف العنوان',
    'confirm_delete_address' => 'حذف العنوان «:label»',
    'confirm_delete_address_body' => 'الطلبات السابقة تحتفظ بالعنوان الذي أُرسلت إليه. ولا يمكن التراجع عن ذلك.',

    'close_account' => 'إغلاق الحساب',
    'close_account_body' => 'يُقفل حسابك عند التأكيد ويُجهَّل بعد :count يومًا، إلا إذا سجّلت الدخول قبل ذلك.',
    'close_account_signs_out' => 'التأكيد يسجّل خروجك من كل مكان في الحال، فهذه آخر صفحة ستراها وأنت داخل.',
    'close_account_confirm' => 'إغلاق الحساب',
    'closed' => 'تم إغلاق الحساب، ويُحذف في :date ما لم تسجّل الدخول قبل ذلك',

    // B5 — أين أنت مسجَّل الدخول، وأي المتصفحات تتخطى رمزك (المالك، 2026-09-26).
    'sessions' => 'أين أنت مسجَّل الدخول',
    'sessions_hint' => 'كل متصفّح داخل إلى حسابك الآن؛ أنهِ أي متصفّح لا تعرفه.',
    'this_browser' => 'هذا المتصفّح',
    'session_ip' => 'العنوان',
    'session_seen' => 'آخر استخدام',
    'unknown_device' => 'متصفّح غير معروف',
    'end_session' => 'تسجيل خروج المتصفّح',
    'end_this_session' => 'تسجيل خروج هذا المتصفّح',
    'end_this_session_warning' => 'هذا هو المتصفّح الذي تستعمله الآن، وإنهاؤه يسجّل خروجك فورًا.',

    'sign_out_everywhere' => 'تسجيل الخروج من كل مكان',
    'sign_out_everywhere_body' => 'ينهي كل الجلسات بما فيها هذه، ويجعل كل متصفّح موثوق يطلب رمزًا من جديد.',
    'sign_out_everywhere_hint' => 'استعمله إذا دخل أحد غيرك إلى حسابك.',

    'trusted_browsers' => 'متصفحات تتخطى رمزك',
    'trusted_browsers_hint' => 'هذه تدخل بكلمة مرورك وحدها دون رمز، إلى أن تنتهي مدّتها.',
    'no_trusted_browsers' => 'كل متصفّح يطلب رمزًا.',
    'trusted_until' => 'حتى :date',
    'forget_browser' => 'إلغاء الثقة بالمتصفّح',
    'forget_all_browsers' => 'إلغاء الثقة بكل المتصفحات',
    // What each confirmation says, the consequence first (frontend.md §1.11: a confirm dialog before
    // ending another browser's session, Forget Browser and Forget All Browsers).
    'end_session_body' => 'يُسجَّل خروج ذلك المتصفّح فورًا.',
    'forget_browser_body' => 'يبقى داخلًا إن كان داخلًا الآن، ويطلب رمزًا عند دخوله التالي.',
    'forget_all_browsers_body' => 'يبقى كل منها داخلًا إن كان داخلًا الآن، ويطلب رمزًا عند دخوله التالي.',

    'session_ended' => 'تم تسجيل خروج المتصفّح',
    'signed_out_everywhere' => 'تم تسجيل الخروج من كل مكان',
    'trusted_browser_forgotten' => 'تم إلغاء الثقة بالمتصفّح',
    'trusted_browsers_forgotten' => 'تم إلغاء الثقة بكل المتصفحات',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'profile_title' => 'الملف الشخصي',
    'saving_reason' => 'يجري حفظ تغييرك الأخير.',
    'no_trusted_browsers_title' => 'لا متصفحات موثوقة',
    'no_addresses_title' => 'لا عناوين بعد',
    'delete_address_open' => 'حذف العنوان…',
    'close_account_open' => 'إغلاق الحساب…',
    'close_account_password_first' => 'أدخل كلمة المرور أولًا.',
];
