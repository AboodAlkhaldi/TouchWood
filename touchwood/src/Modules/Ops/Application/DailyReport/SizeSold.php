<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Shared\Domain\ValueObject\Money;

/** One of the sizes sold most (ops.md §1.11 item 4), named in both languages as Catalog names it. */
final readonly class SizeSold
{
    public function __construct(
        public string $variantId,
        public string $code,
        public string $productNameAr,
        public string $productNameEn,
        /** The size's values, "Colour: Oak · Length: 2 m", in each language; empty for a product with one size. */
        public string $sizeAr,
        public string $sizeEn,
        public int $units,
        public Money $revenue,
    ) {}
}
