<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * A variant as the modules above Catalog read it (catalog.md §2.1): its code — for staff, Sync and
 * an order's record, **never for a shopper's eyes** (amendment 5(d)) — its product, its values and
 * its physical facts (§1.2).
 */
final readonly class VariantDto
{
    /**
     * @param  list<VariantValueDto>  $values  in the product's order of its variant attributes (amendment 16(b))
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $code,
        public array $values,
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
        public bool $isArchived,
    ) {}
}
