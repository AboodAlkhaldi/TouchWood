<?php

declare(strict_types=1);

// سبب تعذّر قراءة المزوّد من محوّله، محفوظًا رمزًا وقيمه (ProviderReason، الخطوة 10 من المرحلة 5):
// يظهر تحت "قال المزوّد:" في شاشات المزامنة، وفي رفض نموذج المزوّد (قواعد connection_failed في errors.php).
// العدد لا يسبق معدودًا (الثواني، الصفحات): يُكتب بين قوسين بعد اسمه، فيصحّ لكل عدد (10 ثوانٍ، 30 ثانية).
return [
    'ODOO_TOO_MANY_PAGES' => 'تجاوزت عناصر Odoo الحدّ الأقصى للصفحات (:pages).',
    'ODOO_NOT_HTTPS' => 'يجب أن يبدأ العنوان بـ https://.',
    'ODOO_TOO_MANY_CALLS' => 'أجاب Odoo بـ 429 (طلبات كثيرة جدًا).',
    'ODOO_TOO_MANY_CALLS_TWICE' => 'أجاب Odoo بـ 429 (طلبات كثيرة جدًا) مرتين.',
    'ODOO_REFUSED_TOKEN' => 'رفض Odoo المفتاح (401).',
    'ODOO_REFUSED_REQUEST' => 'رفض Odoo الطلب (400).',
    'ODOO_FAILED' => 'تعطّل Odoo (:status).',
    'ODOO_ANSWERED' => 'أجاب Odoo بـ :status.',
    'ODOO_UNREACHABLE' => 'تعذّر الوصول إلى Odoo.',
    'ODOO_UNREACHABLE_WITHIN' => 'تعذّر الوصول إلى Odoo خلال المهلة المحددة بالثواني (:seconds).',
    'ODOO_NO_ANSWER_WITHIN' => 'لم يُجب Odoo خلال المهلة المحددة بالثواني (:seconds).',
    'ODOO_UNSENDABLE' => 'تعذّر سؤال Odoo: يحوي العنوان أو المفتاح حرفًا لا يمكن إرساله.',
    'ODOO_NOT_JSON' => 'إجابة Odoo ليست كما اتُّفق: ليست JSON.',
    'ODOO_ITEMS_NOT_LIST' => 'إجابة Odoo ليست كما اتُّفق: عناصرها ليست قائمة.',
    'ODOO_ITEM_WITHOUT_CODE' => 'إجابة Odoo ليست كما اتُّفق: عنصر بلا رمز.',
    'ODOO_EMPTY_PAGE_NAMES_NEXT' => 'إجابة Odoo ليست كما اتُّفق: صفحة فارغة تسمّي صفحة تالية.',
    'ODOO_PAGES_REPEAT' => 'إجابة Odoo ليست كما اتُّفق: صفحاتها تتكرر.',
    'ODOO_NEXT_NOT_TEXT' => 'إجابة Odoo ليست كما اتُّفق: صفحتها التالية ليست نصًا.',
];
