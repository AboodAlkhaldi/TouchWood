<?php

declare(strict_types=1);

// سبب تعذّر قراءة المزوّد من محوّله، محفوظًا رمزًا وقيمه (ProviderReason، الخطوة 10 من المرحلة 5):
// يظهر تحت "قال المزوّد:" في شاشات المزامنة، وفي رفض نموذج المزوّد (قواعد connection_failed في errors.php).
// العدد لا يسبق معدودًا (الثواني): يُكتب بين قوسين بعد اسمه، فيصحّ لكل عدد (10 ثوانٍ، 30 ثانية).
return [
    'ODOO_NOT_HTTPS' => 'يجب أن يبدأ العنوان بـ https://.',
    'ODOO_TOO_MANY_CALLS' => 'أجاب Odoo بـ 429 (طلبات كثيرة جدًا).',
    'ODOO_TOO_MANY_CALLS_TWICE' => 'أجاب Odoo بـ 429 (طلبات كثيرة جدًا) مرتين.',
    'ODOO_REFUSED_TOKEN' => 'رفض Odoo المفتاح (401).',
    'ODOO_REFUSED_ACCESS' => 'رفض Odoo الوصول (403): مستخدم المفتاح لا يملك قراءة المنتجات.',
    'ODOO_REFUSED_REQUEST' => 'رفض Odoo الطلب (400).',
    'ODOO_FAILED' => 'تعطّل Odoo (:status).',
    'ODOO_ANSWERED' => 'أجاب Odoo بـ :status.',
    'ODOO_UNREACHABLE' => 'تعذّر الوصول إلى Odoo.',
    'ODOO_UNREACHABLE_WITHIN' => 'تعذّر الوصول إلى Odoo خلال المهلة المحددة بالثواني (:seconds).',
    'ODOO_NO_ANSWER_WITHIN' => 'لم يُجب Odoo خلال المهلة المحددة بالثواني (:seconds).',
    'ODOO_UNSENDABLE' => 'تعذّر سؤال Odoo: يحوي العنوان أو المفتاح حرفًا لا يمكن إرساله.',
    'ODOO_NOT_JSON' => 'إجابة Odoo ليست كما اتُّفق: ليست JSON.',
    'ODOO_ANSWER_NOT_LIST' => 'إجابة Odoo ليست كما اتُّفق: ليست قائمة.',
    'ODOO_ITEM_WITHOUT_ID' => 'إجابة Odoo ليست كما اتُّفق: عنصر بلا معرّف عددي صحيح.',
    'ODOO_ANSWER_TOO_LARGE' => 'إجابة Odoo ليست كما اتُّفق: حجمها أكبر من 5 MB.',
];
