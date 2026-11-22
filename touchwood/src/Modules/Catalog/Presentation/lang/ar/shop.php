<?php

declare(strict_types=1);

// The shop's pages (catalog.md §4.5): the menu and the search box in every page's frame, a
// category's, a brand's and a product's pages, the search results. No price, no stock, no code.
return [
    'frame' => [
        'all_categories' => 'جميع الأقسام',
        'categories' => 'الأقسام',
        'open_menu' => 'افتح الأقسام',
        'search' => 'بحث',
        'search_placeholder' => 'ابحث عن منتج…',
        'see_all' => 'عرض كل النتائج',
        'no_suggestions' => 'لا منتجات مطابقة بعد.',
        'order_wholesale' => 'اطلب بالجملة',
        'all_of' => 'كل :name',
    ],
    'home' => 'الرئيسية',
    'last_pieces' => 'آخر القطع',
    'sub_categories' => 'الأقسام الفرعية',
    'products' => 'المنتجات',
    'filters' => [
        'title' => 'التصفية',
        'brands' => 'العلامات التجارية',
        'sides' => 'طريقة البيع',
        'retail' => 'تجزئة',
        'wholesale' => 'جملة',
        'clear_all' => 'مسح الكل',
        'remove' => 'إزالة :name',
        'none' => 'لا شيء هنا يطابق هذه التصفية.',
        'open' => 'عرض التصفية',
        // The phone's sticky bar (shop-design.md §5.4).
        'filter' => 'التصفية',
    ],
    'sort' => [
        'label' => 'الترتيب',
        'best-selling' => 'الأكثر مبيعًا',
        'newest' => 'الأحدث',
        // Where the store's lists show ratings (amendment 25; feedback.md §4.1 F7).
        'rating' => 'الأعلى تقييمًا',
        // By this viewer's card price (shop-design.md §5.3, C8).
        'price-low' => 'السعر: من الأقل إلى الأعلى',
        'price-high' => 'السعر: من الأعلى إلى الأقل',
        'by' => 'الترتيب:',
    ],
    // «عرض 1–24 من 918» under a category's name (shop-design.md §5.1).
    'showing' => 'عرض 1–:shown من :total',
    // The grid or the technical list (shop-design.md §5.3, C7).
    'view' => ['label' => 'طريقة العرض', 'grid' => 'شبكة', 'list' => 'قائمة', 'photo' => 'الصورة', 'product' => 'المنتج', 'size' => 'المقاس', 'price' => 'السعر', 'buy' => 'الشراء', 'one_size' => 'مقاس واحد', 'sizes' => 'المقاسات: :count'],
    // A card's stars, read aloud (feedback.md §4.1 F7).
    'card_rating' => ['zero' => ':rating من 5 نجوم، :count تقييم', 'one' => ':rating من 5 نجوم، تقييم واحد', 'two' => ':rating من 5 نجوم، تقييمان', 'few' => ':rating من 5 نجوم، :count تقييمات', 'many' => ':rating من 5 نجوم، :count تقييمًا', 'other' => ':rating من 5 نجوم، :count تقييم'],
    // A card's Save heart (feedback.md §4.1 F7; amendment 32).
    'card_save' => ['add' => 'حفظ :name', 'remove' => 'إزالة :name من المحفوظات'],
    'empty' => 'لا شيء معروض هنا بعد.',
    'nothing_now' => 'لا يتوفر شيء هنا الآن.',
    // The shop's prices, without VAT (amendment 35; sales.md §4.1 S1).
    'price' => ['from' => 'ابتداءً من', 'wholesale_from' => 'الجملة ابتداءً من', 'was' => 'كان', 'off' => '−:percent%'],
    'card' => ['add' => 'أضف إلى السلة', 'choose' => 'اختر الخيارات', 'add_name' => 'أضف :name إلى السلة', 'choose_name' => 'اختر خيارات :name', 'added' => 'أُضيف :name إلى سلتك.', 'wholesale_individual' => 'متاح بالجملة لحسابات الشركات', 'save_failed' => 'تعذّر حفظ :name. حاول مرة أخرى.'],
    'flag' => ['best_seller' => 'الأكثر مبيعًا', 'new_arrival' => 'وصل حديثًا'],
    'unit' => ['PIECE' => 'للحبة', 'SET' => 'للطقم', 'PAIR' => 'للزوج', 'METRE' => 'للمتر', 'CARTON' => 'للكرتون', 'BOX' => 'للعلبة'],
    'saving' => 'وفّر :amount',
    'product' => [
        'photo' => 'الصورة :number من :total',
        'details' => 'التفاصيل',
        'weight' => 'الوزن',
        'size' => 'الأبعاد',
        'grams' => ':value غ',
        'size_value' => ':length × :width × :height مم',
        'description' => 'الوصف',
        'warranty' => 'الضمان',
        'lifetime' => 'مدى الحياة',
        'months' => ':count شهرًا',
        'one_month' => 'شهر واحد',
        'how_sold' => 'طريقة البيع',
        'retail' => 'تجزئة',
        'wholesale' => 'جملة',
        'at_least' => 'لا يقل عن :count',
        'at_most' => 'ولا يزيد على :count',
        'goes_with' => 'يُستخدم معه',
        'may_also_like' => 'قد يعجبك أيضًا',
        'not_available' => 'غير متاح',
        'not_available_text' => 'لا يمكن طلب هذا المنتج هنا الآن.',
        'unavailable_value' => ':name، غير متاح',
        'other_option_value' => ':name، متوفر بخيار آخر',
        // The product page to the design (shop-design.md §6).
        'tax_note' => 'الأسعار قبل الضريبة',
        'wholesale_note' => 'الجملة ابتداءً من :count قطعة',
        'delivery' => 'التوصيل خلال :count أيام عمل',
        'returns' => 'إرجاع خلال :count يومًا من الاستلام',
        'specs' => 'المواصفات',
        'returns_warranty' => 'الإرجاع والضمان',
        'no_warranty' => 'لا يشمل هذا المنتج ضمانًا.',
        'photo_count' => ':number / :total',
        // flags-2026-11.md S4.4: فيديو بعد الصور، ولا يُحمَّل مشغّل يوتيوب إلا عند الضغط على التشغيل.
        'video' => 'الفيديو :number من :total',
        'video_play' => 'تشغيل الفيديو',
    ],
    'search' => [
        'title' => 'البحث',
        'one' => 'نتيجة واحدة عن «:query»',
        'many' => ':count نتيجة عن «:query»',
        'none' => 'لا منتجات تطابق «:query».',
        'browse' => 'تصفّح الأقسام بدلًا من ذلك:',
        'short' => 'اكتب حرفين على الأقل للبحث.',
    ],
    'not_found' => [
        'title' => 'الصفحة غير موجودة',
        'back' => 'العودة إلى المتجر',
    ],
];
