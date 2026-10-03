<?php

declare(strict_types=1);

// شاشات الأدوار (frontend.md §3.4، D1–D5). الدور مجموعة صلاحيات لها اسم؛ أما المتاجر التي يصل
// إليها فتُختار لكل موظف على حدة، لا هنا.
return [
    'title' => 'الأدوار',
    'subtitle' => 'ما يمكن لكل فئة من الموظفين فعله.',

    'name' => 'الاسم',
    'name_ar' => 'الاسم بالعربية',
    'name_en' => 'الاسم بالإنجليزية',
    'level' => 'المستوى',
    'level_admin' => 'إداري',
    'level_staff' => 'موظف',
    'actions_count' => ':count صلاحية',
    'holders_count' => ':count أشخاص',
    // Geist's Empty State: the blank slate names the next action (shadcn rebuild).
    'no_roles' => 'أنشئ دورًا لتمنح الموظفين مجموعة من الصلاحيات.',

    'new' => 'إنشاء دور',
    'edit' => 'تعديل الدور',
    'clone' => 'نسخ الدور',
    'delete' => 'حذف الدور',
    'refresh' => 'تحديث الصلاحيات',
    'save' => 'حفظ الدور',
    'cancel' => 'إلغاء',

    // The plain table's first two column headers: the business area, then the action (§1.11 #5).
    'area' => 'المجال',
    'action' => 'الصلاحية',
    'reaches' => 'يصل إليه',
    'does_not_reach' => 'لا يصل',
    'filter_areas' => 'تصفية المجالات',
    'columns' => 'الأدوار: :shown من :total',
    // A menu section's header: one or two words (Geist's Menu).
    'columns_hint' => 'الأدوار',
    // Geist's Empty State quotes the typed filter and offers to clear it.
    'no_areas' => 'امسح التصفية لترى كل المجالات.',
    'clear_filter' => 'مسح التصفية',
    'comparison' => 'الصلاحيات حسب الدور',
    'comparison_hint' => 'المجالات التي يصل إليها كل دور.',

    'actions' => 'ما يسمح به',
    'holders' => 'من يحمله',
    'holders_hint' => 'لا تظهر إلا أسماء من تديرهم؛ أما العدد فهو للجميع.',
    'no_holders' => 'يظهر هنا الموظفون الذين يُمنحون هذا الدور.',
    'every_store' => 'كل المتاجر',
    'store_free' => 'كل المتاجر، بطبيعتها',
    'not_editable' => 'لا يغيّر الدور الإداري إلا مدير عام.',

    'new_title' => 'إنشاء دور',
    'edit_title' => 'تعديل الدور',
    // Geist's Checkbox group: the count beside the group's name says how many of how many.
    'chosen_count' => ':count من :total مختارة',
    'not_yours' => 'لا تملك هذه الصلاحية، فلا يمكنك منحها.',
    'holders_warning' => 'تعديل هذا الدور يغيّره لـ :count من يحملونه.',
    'level_locked' => 'لا يتغيّر مستوى الدور بعد إنشائه.',

    'delete_title' => 'حذف الدور',
    'delete_question' => 'يجب نقل كل من يحمله إلى دور آخر من المستوى نفسه.',
    'replacement' => 'الدور البديل',
    'delete_confirm' => 'حذف الدور',
    'delete_none_left' => 'لا يوجد دور آخر بهذا المستوى لنقلهم إليه.',

    'created' => 'تم إنشاء الدور',
    'saved' => 'تم حفظ الدور',
    'cloned' => 'تم نسخ الدور',
    'deleted' => 'تم حذف الدور',
    'refreshed' => 'تم تحديث صلاحيات كل من يحمل الدور',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'none_title' => 'لا أدوار بعد',
    'no_areas_title' => 'لا مجالات مطابقة',
    'no_holders_title' => 'لا أحد يحمله',
    'verification_label' => 'اسم الدور',
    'delete_body' => 'سيُحذف الدور «:name» نهائيًا.',
    // Geist's Destructive Action Modal: the red band names the action and the thing.
    'delete_irreversible' => 'لا يمكن التراجع عن حذف «:name».',
];
