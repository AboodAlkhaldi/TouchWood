<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditWarranty;

/**
 * A warranty's form, sent whole (catalog.md §1.9).
 */
final readonly class EditWarranty
{
    /**
     * @param  array<array-key, mixed>  $termsAr
     * @param  array<array-key, mixed>  $termsEn
     */
    public function __construct(
        public string $warrantyId,
        public string $nameAr,
        public string $nameEn,
        public array $termsAr,
        public array $termsEn,
        public ?int $periodMonths,
    ) {}
}
