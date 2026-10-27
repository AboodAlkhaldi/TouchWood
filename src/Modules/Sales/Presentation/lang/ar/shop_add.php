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
        'out_of_stock' => 'نفدت الكمية',
        'full' => 'سلتك تحوي أقصى ما يمكنك شراؤه من هذا المقاس.',
        'in_cart' => ['zero' => ':count قطعة في سلتك', 'one' => 'قطعة واحدة في سلتك', 'two' => 'قطعتان في سلتك', 'few' => ':count قطع في سلتك', 'many' => ':count قطعة في سلتك', 'other' => ':count قطعة في سلتك'],
    ],
    // The wholesale prices by quantity, under the Wholesale choice (§4.1 S1; R2-3).
    'bands' => [
        'title' => 'أسعار الجملة',
        'from' => ['zero' => 'من :count قطعة', 'one' => 'من قطعة واحدة', 'two' => 'من قطعتين', 'few' => 'من :count قطع', 'many' => 'من :count قطعة', 'other' => 'من :count قطعة'],
    ],
];
