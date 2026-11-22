<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/** What a coupon rule names (promotions.md §1.2, §5: exactly one of the three). */
enum RuleTarget: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
}
