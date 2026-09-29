<?php

declare(strict_types=1);

// أسماء إجراءات وحدة الشركات في سجل التدقيق (frontend.md 3.5، E6). الإجراء الذي لا سطر له يظهر كما
// سُجّل.
return [
    'application.submitted' => 'إرسال طلب شركة',
    'application.discarded' => 'حذف مسودة طلب شركة',
    'company.address_changed' => 'تغيير عنوان الشركة',
    // النظام، عند إخفاء هوية الحساب (المرحلة 5، التعديل 12(أ)).
    'company.anonymized' => 'إفراغ بيانات الشركة مع إخفاء هوية حسابها',
    // الموظفون (المرحلة 4، التعديل 10).
    'application.approved' => 'قبول طلب شركة',
    'application.rejected' => 'رفض طلب شركة',
    'company.suspended' => 'تعليق شركة',
    'company.reinstated' => 'إعادة شركة',
    'company.type_corrected' => 'تصحيح نوع شركة',
    'company.type_replaced' => 'استبدال نوع شركة',
    'company.type_transferred' => 'نقل شركة إلى نوع آخر',
    'company.document_opened' => 'فتح مستند شركة',
    'company_type.added' => 'إضافة نوع شركة',
    'company_type.renamed' => 'إعادة تسمية نوع شركة',
    'company_type.moved' => 'تغيير ترتيب نوع شركة',
    'company_type.deactivated' => 'إيقاف نوع شركة',
    'company_type.activated' => 'إعادة تفعيل نوع شركة',
    'company_type.transferred' => 'نقل الشركات من نوع إلى آخر',
    'document_type.added' => 'إضافة نوع مستند',
    'document_type.renamed' => 'إعادة تسمية نوع مستند',
    'document_type.moved' => 'تغيير ترتيب نوع مستند',
    'document_type.requirement_changed' => 'جعل نوع مستند مطلوبًا أو اختياريًا',
    'document_type.deactivated' => 'إيقاف نوع مستند',
    'document_type.activated' => 'إعادة تفعيل نوع مستند',
    'type_lists.reviewed' => 'مراجعة أنواع الشركات والمستندات',
];
