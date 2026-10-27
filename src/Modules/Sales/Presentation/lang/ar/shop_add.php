<?php

declare(strict_types=1);

// المبيعات في صفحة المنتج: أضف إلى السلة (المواصفة §4.1 S2). منفصلة عن كلمات السلة التي لا تحتاجها الصفحة.
return [
    'mode' => ['RETAIL' => 'تجزئة', 'WHOLESALE' => 'جملة'],
    'pieces' => ['zero' => ':count قطعة', 'one' => 'قطعة واحدة', 'two' => 'قطعتان', 'few' => ':count قطع', 'many' => ':count قطعة', 'other' => ':count قطعة'],
    'add' => [
        'mode' => 'الشراء',
        'quantity' => 'عدد القطع',
        'limits' => 'من :minimum إلى :maximum قطعة',
        'from' => ':minimum قطعة على الأقل',
        'add' => 'أضف إلى السلة',
        'choose' => 'اختر المقاس أولًا',
        'added' => 'أُضيف إلى السلة · :pieces',
        'view' => 'عرض السلة',
    ],
];
