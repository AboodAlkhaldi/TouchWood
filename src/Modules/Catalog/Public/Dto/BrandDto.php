<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * A brand as another module needs it (amendment 20): its names and whether it is switched on — so a
 * coupon's include or exclude list names only an active brand (promotions.md §1.2).
 */
final readonly class BrandDto
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $isActive,
    ) {}
}
