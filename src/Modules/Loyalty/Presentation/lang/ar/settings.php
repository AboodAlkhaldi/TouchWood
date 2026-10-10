<?php

declare(strict_types=1);

// أسماء إعدادات برنامج النقاط كما تعرضها شاشة الإعدادات (ProgrammeSettings، المواصفة §1.5).
// مجموعة واحدة لكل متجر، ولا يغيّرها إلا مسؤول.
return [
    'module' => 'النقاط',

    'points.enabled' => 'تفعيل برنامج النقاط',
    'points.earn_per_unit' => 'النقاط المكتسبة لكل وحدة عملة واحدة تُنفق',
    'points.points_per_unit_off' => 'عدد النقاط مقابل خصم وحدة عملة واحدة',
    'points.expiry_months' => 'مدة صلاحية النقاط (بالأشهر)',
    'points.minimum_redemption' => 'أقل عدد من النقاط في الاستبدال الواحد',
    'points.max_redemption_percent' => 'أقصى نسبة من المجموع الفرعي للطلب تدفعها النقاط (%)',
    'redemption.public_partial' => 'يختار الأفراد عدد النقاط التي يستخدمونها',
    'redemption.company_partial' => 'تختار الشركات عدد النقاط التي تستخدمها',
];
