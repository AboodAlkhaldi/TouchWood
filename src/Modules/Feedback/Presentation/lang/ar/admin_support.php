<?php

declare(strict_types=1);

// التقييمات وأسئلة المنتجات في الدعم، والمنتجات المفضلة في العملاء (المواصفة §4.1 F1–F3).
return [
    'reviews' => [
        'title' => 'التقييمات',
        'subtitle' => 'ما كتبه العملاء، بانتظار قرارك',
        'tabs_label' => 'التقييمات حسب الحالة',
        'empty' => 'لا توجد تقييمات هنا.',
    ],
    'questions' => [
        'title' => 'أسئلة المنتجات',
        'subtitle' => 'أسئلة العملاء عن المنتجات',
        'tabs_label' => 'الأسئلة حسب الحالة',
        'empty' => 'لا توجد أسئلة هنا.',
    ],
    'favourites' => [
        'title' => 'المنتجات المفضلة',
        'subtitle' => 'المنتجات التي حفظها العملاء في هذا المتجر، الأكثر حفظًا أولًا',
        'empty' => 'لم يُحفظ أي منتج بعد.',
        'saved_by' => 'عدد من حفظه',
        'product' => 'المنتج',
    ],
    'wishlist' => [
        'title' => 'المنتجات المحفوظة',
        'none' => 'لا شيء محفوظ في متاجرك.',
    ],
    'review_status' => [
        'PENDING' => 'بالانتظار',
        'APPROVED' => 'منشورة',
        'REJECTED' => 'غير منشورة',
        'UNPUBLISHED' => 'أُلغي نشرها',
    ],
    'question_status' => [
        'PENDING' => 'بالانتظار',
        'ANSWERED' => 'مُجابة',
        'REJECTED' => 'مرفوضة',
        'UNPUBLISHED' => 'أُلغي نشرها',
    ],
    'mark' => [
        'VERIFIED_PURCHASE' => 'شراء موثّق',
        'RETURNED' => 'مُرتجع',
    ],
    'item' => [
        'archived' => 'مؤرشف',
        'not_sold' => 'لا يُباع هنا',
        'removed' => 'لم يعد في الكتالوج',
        'deleted_customer' => 'عميل محذوف',
        'author' => ':first :initial.',
        'stars' => ':rating من 5 نجوم',
        'decided' => 'قرّر ذلك :name',
        'answered_by' => 'أجاب :name',
        'reason' => 'السبب: :reason',
    ],
    'actions' => [
        'approve' => 'موافقة',
        'reject' => 'رفض',
        'unpublish' => 'إلغاء النشر',
        'answer' => 'إجابة',
        'correct' => 'تصحيح الإجابة',
    ],
    'dialog' => [
        'reject_review' => 'رفض التقييم',
        'unpublish_review' => 'إلغاء نشر التقييم',
        'reject_question' => 'رفض السؤال',
        'unpublish_question' => 'إلغاء نشر السؤال',
        'reason' => 'السبب',
        'reason_hint' => 'اختياري. مع السبب يراه العميل في حسابه؛ ومن دونه يرى فقط "لم يُنشر".',
        'answer_title' => 'الإجابة عن السؤال',
        'correct_title' => 'تصحيح الإجابة',
        'answer' => 'الإجابة',
        'answer_hint' => 'تُوقَّع "فريق :store" في كل متجر يبيع المنتج.',
        'save_answer' => 'نشر الإجابة',
    ],
    'toast' => [
        'approved' => 'تمت الموافقة على التقييم.',
        'rejected' => 'رُفض التقييم.',
        'unpublished' => 'أُلغي نشر التقييم.',
        'answered' => 'نُشرت الإجابة.',
        'corrected' => 'صُحّحت الإجابة.',
        'question_rejected' => 'رُفض السؤال.',
        'question_unpublished' => 'أُلغي نشر السؤال.',
    ],
];
