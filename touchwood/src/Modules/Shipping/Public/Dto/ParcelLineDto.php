<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

/** One size in a parcel and how many (sales.md §2.4) — Shipping reads its weight and size from Catalog. Provisional. */
final readonly class ParcelLineDto
{
    public function __construct(
        public string $variantId,
        public int $quantity,
    ) {}
}
