<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **A size's wholesale terms in a store** (catalog.md amendment 23(d)): its product's wholesale minimum
 * and maximum there (none until the store sets them), and whether the size sells wholesale there - its
 * selling mode, kept while it is switched off, as `storeVariant()` answers it - and whether it sells one
 * piece at a time there (`soldRetail`, stage 5's screens step 2: Pricing's "Wholesale Only").
 */
final readonly class WholesaleTermsDto
{
    public function __construct(
        public string $variantId,
        public ?int $minimum,
        public ?int $maximum,
        public bool $soldWholesale,
        public bool $soldRetail,
    ) {}
}
