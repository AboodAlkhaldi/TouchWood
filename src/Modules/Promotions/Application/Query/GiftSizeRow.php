<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/**
 * A size the gift-level dialog may give (promotions.md M4): its product's names, its code (staff only),
 * its product's first photo, whether its normal sales already depend on its stock in this store, and
 * whether the store is wired — the tick box shown only where it can do something.
 */
final readonly class GiftSizeRow
{
    public function __construct(
        public string $variantId,
        public string $productNameAr,
        public ?string $productNameEn,
        public string $code,
        public ?string $photoMediaId,
        public bool $stockDependent,
        public bool $wired,
    ) {}
}
