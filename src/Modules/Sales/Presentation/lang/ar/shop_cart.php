<?php

declare(strict_types=1);

// المبيعات في المتجر: السلة وبنودها وطرق إصلاحها (المواصفة §4.1 S3، S4).
return [
    'title' => 'السلة',
    'header' => 'السلة',
    'pieces' => ['zero' => ':count قطعة', 'one' => 'قطعة واحدة', 'two' => 'قطعتان', 'few' => ':count قطع', 'many' => ':count قطعة', 'other' => ':count قطعة'],
    'empty' => 'سلتك فارغة',
    'continue' => 'متابعة التسوق',
    'best_sellers' => 'الأكثر مبيعًا',
    'mode' => ['RETAIL' => 'تجزئة', 'WHOLESALE' => 'جملة'],
    'line' => [
        'lines' => 'المنتجات في سلتك',
        'quantity' => 'عدد قطع :name',
        'less' => 'قطعة أقل',
        'more' => 'قطعة أكثر',
        'remove' => 'إزالة',
        'unit' => 'للقطعة',
        'no_price' => 'لا يوجد سعر بعد',
        'limits' => 'من :minimum إلى :maximum قطعة',
        'from' => ':minimum قطعة على الأقل',
    ],
    'problem' => [
        'NOT_AVAILABLE' => 'لم يعد متاحًا.',
        'NOT_FOR_YOUR_ACCOUNT' => 'لا يُباع لحسابك.',
        'BELOW_MINIMUM' => ':minimum قطعة على الأقل.',
        'ABOVE_MAXIMUM' => ':maximum قطعة على الأكثر.',
        'OUT_OF_STOCK' => 'نفد من المخزون.',
        'NOT_PRICED' => 'لم يُسعَّر بعد.',
        'set_to' => 'اجعلها :quantity',
    ],
    'notice' => [
        'title' => 'خرجت بعض المنتجات من سلتك عند تسجيل الدخول',
        'WHOLESALE' => 'الجملة لحسابات الشركات.',
        'NOT_FOR_YOUR_ACCOUNT' => 'لا يُباع لحسابك.',
        'close' => 'إغلاق',
    ],
    'coupon' => [
        'label' => 'رمز القسيمة',
        'apply' => 'تطبيق',
        'remove' => 'إزالة',
        'kept' => 'تُطبَّق القسيمة :code عند إتمام الطلب.',
        'kept_for' => 'تُطبَّق عند إتمام الطلب.',
        'sign_in' => 'سجّل الدخول لاستخدام قسيمة',
    ],
    'summary' => [
        'title' => 'الملخص',
        'subtotal' => 'المجموع الفرعي',
        'later' => 'تُحسب القسيمة والنقاط وضريبة القيمة المضافة عند إتمام الطلب.',
        'shipping' => 'الشحن',
        'free_above' => 'مجاني فوق',
        'free' => 'مجاني',
        'checkout' => 'إتمام الطلب',
        'fix_first' => 'أصلح البنود المعلَّمة أولًا',
        'sign_in_first' => 'سجّل الدخول أو أنشئ حسابًا لإتمام الطلب، وستبقى سلتك معك.',
    ],
    // Why this account may not order yet, and its one fix (R1-17; §4.2 B12).
    'block' => [
        'ACCOUNT_NOT_ACTIVE' => 'لا يمكن لحسابك تقديم طلبات. تواصل مع المتجر للمساعدة.',
        'EMAIL_UNVERIFIED' => 'أكّد بريدك الإلكتروني لتقديم الطلبات.',
        'PHONE_UNVERIFIED' => 'أكّد رقم جوالك لتقديم الطلبات.',
        'COMPANY_NOT_APPROVED' => 'يجب اعتماد شركتك هنا قبل أن تطلب.',
        'fix' => [
            'EMAIL_UNVERIFIED' => 'تأكيد البريد الإلكتروني',
            'PHONE_UNVERIFIED' => 'تأكيد رقم الجوال',
            'COMPANY_NOT_APPROVED' => 'عرض شركتك',
        ],
    ],
    // Free shipping, the next gift and the points (R1-12 to R1-14).
    'extras' => [
        'add_more' => 'أضف',
        'for_free_shipping' => 'أخرى ليصبح الشحن مجانيًا.',
        'ships_free' => 'طلبك يُشحن مجانًا.',
        'free_bar' => 'التقدّم نحو الشحن المجاني',
        'spend_about' => 'أنفق نحو',
        'for_a_gift' => 'أخرى لتحصل على هدية مجانية.',
        'points' => ['zero' => 'ستكسب :count نقطة مع هذا الطلب.', 'one' => 'ستكسب نقطة واحدة مع هذا الطلب.', 'two' => 'ستكسب نقطتين مع هذا الطلب.', 'few' => 'ستكسب :count نقاط مع هذا الطلب.', 'many' => 'ستكسب :count نقطة مع هذا الطلب.', 'other' => 'ستكسب :count نقطة مع هذا الطلب.'],
    ],
    'skipped' => [
        'title' => 'تعذّر إضافة بعض المنتجات مجددًا',
        'NOT_AVAILABLE' => 'لم يعد متاحًا.',
        'NOT_FOR_YOUR_ACCOUNT' => 'لا يُباع لحسابك.',
    ],
    'toast' => [
        'added' => 'أُضيف إلى السلة.',
        'removed' => 'أُزيل من سلتك.',
        'coupon_kept' => 'حُفظت القسيمة لإتمام الطلب.',
        'coupon_cleared' => 'أُزيلت القسيمة.',
    ],
];
