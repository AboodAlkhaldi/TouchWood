<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

/**
 * One priced size a discount reaches: its code, its retail price and - unless a fixed amount skips it,
 * taking it to 0 or below - its new price and the difference, in minor units.
 */
final readonly class PreviewSize
{
    public function __construct(
        public string $variantId,
        public string $code,
        public int $retailMinor,
        public ?int $newMinor,
        public ?int $differenceMinor,
    ) {}
}
