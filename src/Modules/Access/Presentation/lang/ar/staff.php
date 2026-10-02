<?php

declare(strict_types=1);

// شاشات الموظفين (frontend.md §3.3، C1–C9). ما يمكن لشخص فعله بآخر جوابٌ من وحدة الوصول؛ وما هنا
// هو الكلمات المحيطة به فقط.
return [
    'title' => 'الموظفون',
    'subtitle' => 'من يعمل هنا، وما يمكن لكلٍّ منهم فعله.',

    // C1.
    // لا يراه إلا المدير العام، فوق الإداريين (التعديل 54).
    'super_admins' => 'المديرون العامّون',
    // كيف يُسمّى المدير العام لمن ليس مديرًا عامًّا (التعديل 54).
    'system_administrator' => 'مدير النظام',
    // مدير عام سُحبت صلاحيته، في قسم المديرين العامّين (التعديل 57).
    'former_super_admin' => 'مدير عام سابق',
    'admins' => 'الإداريون',
    'centralized' => 'مركزي',
    'search' => 'ابحث بالاسم أو البريد',
    'status' => 'الحالة',
    'all_statuses' => 'أي حالة',
    'status_active' => 'نشط',
    'status_invited' => 'مدعو',
    'status_disabled' => 'معطّل',
    // Final: an invitation that was cancelled is not sent again (access.md amendment 29).
    'status_cancelled' => 'ملغى',
    'since' => 'منذ :date',
    'invited_on' => 'دُعي في :date',
    'no_staff' => 'لا أحد هنا بعد.',
    'invite' => 'دعوة موظف',
    'total' => ':count أشخاص',

    // C2.
    'profile' => 'الملف',
    'job_title' => 'المسمى الوظيفي',
    'email' => 'بريد العمل',
    'phone' => 'الجوال',
    'date_of_birth' => 'تاريخ الميلاد',
    'country' => 'الدولة',
    'countries_ours' => 'حيث لنا متاجر',
    'countries_all' => 'كل الدول',
    'address' => 'العنوان',
    'communication_language' => 'لغة المراسلة',
    'communication_language_hint' => 'اللغة التي تُكتب بها رسائله ورموزه.',
    'role' => 'الدور',
    'no_role' => 'لا دور بعد.',
    'stores' => 'المتاجر',
    'every_store' => 'كل المتاجر',
    'allows' => 'ما يسمح به',
    'store_free' => 'كل المتاجر، بطبيعتها',
    'exception' => 'متاجر خاصة به',
    'exception_hint' => 'أُعطيت هذه الصلاحية متاجر خاصة بها، بمعزل عن بقية الدور.',
    'super_admin' => 'مدير عام',
    'super_admin_hint' => 'لا يُنشأ المدير العام ولا يُزال إلا بأمر من الطرفية.',
    'yourself' => 'هذا حسابك أنت. غيّره من «الحساب والإعدادات».',

    // C4–C9.
    'edit_profile' => 'تعديل الملف',
    'change_email' => 'تغيير البريد',
    'change_email_hint' => 'يتم التغيير عند استخدام الرابط المُرسل إلى العنوان الجديد.',
    'new_email' => 'البريد الجديد',
    'change_role' => 'تغيير الدور والمتاجر',
    'disable' => 'تعطيل الموظف',
    'disable_hint' => 'تنتهي جلساته ومتصفحاته الموثوقة في الحال.',
    'enable' => 'تفعيل الموظف',
    'resend_invitation' => 'إعادة إرسال الدعوة',
    'resend_invitation_hint' => 'يتوقف الرابط السابق عن العمل.',
    'cancel_invitation' => 'إلغاء الدعوة',
    'refresh' => 'تحديث الصلاحيات',
    'save' => 'حفظ التغييرات',
    'cancel' => 'إلغاء',

    // بعد التنفيذ.
    'profile_saved' => 'تم حفظ الملف',
    'email_link_sent' => 'تم إرسال الرابط إلى العنوان الجديد',
    'disabled' => 'تم تعطيل الموظف. وانتهت كل جلساته.',
    'enabled' => 'تم تفعيل الموظف',
    'invitation_resent' => 'تم إعادة إرسال الدعوة',
    'invitation_cancelled' => 'تم إلغاء الدعوة',
    'refreshed' => 'تم تحديث الصلاحيات',
    // C6 - دور شخص واحد ومتاجره.
    'role_saved' => 'تم حفظ الدور والمتاجر',
    'personal_role_name' => 'دور :name',
    'pick_role' => 'الدور',
    'pick_role_hint' => 'اختر دورًا محفوظًا، أو عدّله ليصير دورًا خاصًا به.',
    'own_role' => 'دور خاص به',
    'own_role_hint' => 'تعديل دور محفوظ هنا لا يغيّره على بقية من يحملونه، بل يصير دورًا لهذا الشخص وحده.',
    'edited' => 'معدَّل',
    'actions_count' => ':count صلاحية',
    'where' => 'إلى أين يصل',
    'where_hint' => 'الدور يقول ماذا يفعل، والمتاجر تقول أين.',
    'stores_all' => 'كل المتاجر',
    'stores_selected' => 'متاجر مختارة',
    'no_stores_to_give' => 'لا تُعطي إلا المتاجر التي تديرها أنت.',
    'exceptions_title' => 'صلاحيات لها متاجرها',
    'exceptions_hint' => 'قد تصل صلاحية أبعد من بقية الدور، أو أقصر منه.',
    'give_own_stores' => 'أعطها متاجر خاصة بها',
    'follow_the_role' => 'أعدها إلى متاجر الدور',
    'back_to' => 'العودة إلى :name',
    'save_role' => 'حفظ الدور',

    // C3 - الدعوة.
    'invite_title' => 'دعوة موظف',
    'invite_subtitle' => 'من هو، وماذا يفعل، وأين.',
    'step_profile' => 'الملف',
    'step_role' => 'الدور',
    'step_stores' => 'المتاجر',
    'step_of' => 'الخطوة :step من :total',
    'nothing_sent_yet' => 'لا يُرسل شيء قبل الخطوة الأخيرة.',
    'next' => 'الخطوة التالية',
    'back' => 'الخطوة السابقة',
    'send_invitation' => 'إرسال الدعوة',
    'invitation_sent' => 'تم إرسال الدعوة',
    'first_name' => 'الاسم الأول',
    'last_name' => 'اسم العائلة',
    'as_admin' => 'إداري',
    'as_admin_hint' => 'الإداري غير مرتبط بمتجر، ولا يدعوه إلا مدير عام.',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'none_title' => 'لا موظفين',
    'no_role_title' => 'لا صلاحيات بعد',
    'verification_label' => 'اسم الموظف',
    'cancel_invitation_body' => 'يتوقف رابط الدعوة عن العمل، ويمكن إرسال دعوة جديدة لاحقًا.',
    'keep_invitation' => 'الإبقاء على الدعوة',
];
