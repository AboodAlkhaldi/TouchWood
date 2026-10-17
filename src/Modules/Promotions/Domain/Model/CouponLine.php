<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use Shared\Domain\ValueObject\Money;

/**
 * A cart line as a coupon sees it (promotions.md §1.2, §1.3): what its variant belongs to (Catalog's
 * line facts), its sale mode, whether its price is already reduced (`unit` below `listUnit` — a
 * wholesale band is a wholesale line's normal price, owner 2026-10-10), and its total without VAT.
 */
final readonly class CouponLine
{
    /**
     * @param  list<string>  $categoryIds  the product's category and every one above it
     */
    public function __construct(
        public string $productId,
        public string $brandId,
        public array $categoryIds,
        public bool $wholesale,
        public bool $reduced,
        public Money $total,
    ) {}
}
