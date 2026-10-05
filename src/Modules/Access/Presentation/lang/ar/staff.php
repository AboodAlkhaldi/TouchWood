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
    // Before Geist's Relative Time Card, which writes "قبل ٥ ساعات" or a date (shadcn rebuild;
    // "منذ" and "في" read wrongly before a relative time).
    'since' => 'انضم :date',
    'invited_on' => 'دُعي :date',
    // Geist's Empty State: the blank slate names the next action; a filtered list that finds
    // nobody says so, quoting a typed search, and offers to clear the filters (shadcn rebuild).
    'no_staff' => 'ادعُ موظفًا ليصل إلى لوحة التحكم.',
    'no_match_title' => 'لا موظفين يطابقون التصفية',
    'no_match_query' => 'لا موظفين يطابقون «:query». امسح التصفية لترى الجميع.',
    'no_match' => 'وسّع التصفية أو امسحها لترى الجميع.',
    'clear_filters' => 'مسح التصفية',
    // Geist's Search Input: a scoped placeholder.
    'search_placeholder' => 'ابحث في الموظفين',
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
    // The country picker's search (Geist's Combobox): a scoped placeholder, and the typed text quoted
    // when nothing matches.
    'country_search' => 'ابحث في الدول',
    'country_none' => 'لا دول تطابق «:query».',
    'address' => 'العنوان',
    'communication_language' => 'لغة المراسلة',
    'communication_language_hint' => 'اللغة التي تُكتب بها رسائله ورموزه.',
    'role' => 'الدور',
    'no_role' => 'لا دور بعد.',
    'stores' => 'المتاجر',
    'every_store' => 'كل المتاجر',
    'allows' => 'ما يسمح به',
    'store_free' => 'كل المتاجر، بطبيعتها',
    'exception' => 'متاجر مخصّصة',
    'exception_hint' => 'تعمل هذه الصلاحية في بعض المتاجر التي يصل إليها الدور فقط.',
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
    'save' => 'حفظ التغييرات',
    'cancel' => 'إلغاء',

    // بعد التنفيذ.
    'profile_saved' => 'تم حفظ الملف',
    'email_link_sent' => 'تم إرسال الرابط إلى العنوان الجديد',
    'disabled' => 'تم تعطيل الموظف وإنهاء كل جلساته',
    'enabled' => 'تم تفعيل الموظف',
    'invitation_resent' => 'تم إعادة إرسال الدعوة',
    'invitation_cancelled' => 'تم إلغاء الدعوة',
    // C6 - دور شخص واحد ومتاجره.
    'role_saved' => 'تم حفظ الدور والمتاجر',
    'personal_role_name' => 'دور :name',
    'pick_role' => 'الدور',
    'pick_role_hint' => 'اختر دورًا محفوظًا، أو عدّله ليصير دورًا خاصًا به.',
    // Past six saved roles the cards give way to a searchable picker (Geist's Choicebox; owner,
    // 2026-10-03).
    'saved_role' => 'دور محفوظ',
    'choose_saved_role' => 'اختر دورًا محفوظًا',
    'role_search' => 'ابحث في الأدوار',
    'role_none' => 'لا أدوار تطابق «:query».',
    'own_role' => 'دور خاص به',
    'own_role_hint' => 'تعديل دور محفوظ هنا لا يغيّره على بقية من يحملونه، بل يصير دورًا لهذا الشخص وحده.',
    'edited' => 'معدَّل',
    'actions_count' => ':count صلاحية',
    'where' => 'إلى أين يصل',
    'where_hint' => 'يحدّد الدور ما يمكنه فعله، وتحدّد هذه المتاجر أين: لا تتجاوزها أي صلاحية.',
    'stores_all' => 'كل المتاجر',
    'stores_selected' => 'متاجر مختارة',
    'no_stores_to_give' => 'لا تُعطي إلا المتاجر التي تديرها أنت.',
    // متاجر كل صلاحية، حين يصل الدور إلى متجرين أو أكثر أو إلى كل المتاجر (access.md التعديل 59).
    'exceptions_title' => 'متاجر كل صلاحية',
    'exceptions_hint' => 'تعمل كل صلاحية في كل المتاجر أعلاه، إلا إذا اخترت لها بعضها.',
    'exceptions_all' => 'كل المتاجر المختارة',
    'exceptions_custom' => 'مخصّص',
    'exceptions_custom_stores' => 'متاجر :action',
    'exceptions_cut' => 'أُزيل منها ما خرج من «إلى أين يصل»: :stores.',
    'exceptions_emptied' => 'اختر متجرًا واحدًا على الأقل، أو «كل المتاجر المختارة».',
    'exceptions_empty' => 'تعذّر حفظ المتاجر: لم يُختر متجر لـ :actions. اختر متجرًا واحدًا على الأقل لكلٍّ منها، أو «كل المتاجر المختارة».',
    // In place of an empty list, when no chosen action works store by store (Geist's Empty State).
    'exceptions_none_title' => 'لا صلاحيات تعمل متجرًا بمتجر',
    'exceptions_none' => 'كل الصلاحيات المختارة تصل إلى كل المتاجر بطبيعتها.',
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
