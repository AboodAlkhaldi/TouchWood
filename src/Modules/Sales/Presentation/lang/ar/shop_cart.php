<?php

declare(strict_types=1);

// المبيعات في المتجر: السلة وبنودها وطرق إصلاحها (المواصفة §4.1 S3، S4).
return [
    'title' => 'السلة',
    'header' => 'السلة',
    'pieces' => ['zero' => ':count قطعة', 'one' => 'قطعة واحدة', 'two' => 'قطعتان', 'few' => ':count قطع', 'many' => ':count قطعة', 'other' => ':count قطعة'],
    'empty' => 'سلتك فارغة',
    'continue' => 'متابعة التسوق',
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
        'sign_in' => 'سجّل الدخول لاستخدام قسيمة',
    ],
    'summary' => [
        'title' => 'الملخص',
        'subtotal' => 'المجموع الفرعي',
        'later' => 'تُحسب القسيمة والنقاط والشحن وضريبة القيمة المضافة عند إتمام الطلب.',
        'checkout' => 'إتمام الطلب',
        'fix_first' => 'أصلح البنود المعلَّمة أولًا',
        'may_not_order' => 'لا يمكن لحسابك تقديم طلبات بعد.',
        'finish_account' => 'أكمل حسابك',
        'sign_in_first' => 'سجّل الدخول أو أنشئ حسابًا لإتمام الطلب، وستبقى سلتك معك.',
    ],
    'toast' => [
        'added' => 'أُضيف إلى السلة.',
        'removed' => 'أُزيل من سلتك.',
        'coupon_kept' => 'حُفظت القسيمة لإتمام الطلب.',
        'coupon_cleared' => 'أُزيلت القسيمة.',
    ],
];
