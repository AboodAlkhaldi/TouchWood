<?php

declare(strict_types=1);

// How Pricing names a size, a sale and a category discount in a line of text (pricing.md §4.3.3): Sync's
// report names a stopped sale or a skipped discount this way ("−10% · 60 cm, 80 cm", "Kitchens: 50.00 SAR
// off"), and the screens name a size with no values "One Size".
return [
    'one_size' => 'One Size',
    'separator' => ', ',
    'sale_percent' => '−:percent% · :sizes',
    'sale_price' => ':price · :sizes',
    'discount_percent' => ':category: :percent% off',
    'discount_amount' => ':category: :amount off',
];
