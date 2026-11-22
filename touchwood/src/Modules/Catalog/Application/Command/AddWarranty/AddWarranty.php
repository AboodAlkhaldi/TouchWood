<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddWarranty;

/**
 * A new warranty (catalog.md §1.9): a name and terms in both languages — the terms the structured
 * text of §1.1, decoded — and a period of 1 to 600 months, or none for life.
 */
final readonly class AddWarranty
{
    /**
     * @param  array<array-key, mixed>  $termsAr
     * @param  array<array-key, mixed>  $termsEn
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public array $termsAr,
        public array $termsEn,
        public ?int $periodMonths,
    ) {}
}
