<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\NeedsAPrice;

/**
 * A size switched on with no retail price: its product's names (a draft's English may be missing) and
 * its code - for staff, never a shopper.
 */
final readonly class UnpricedSize
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public string $code,
    ) {}
}
