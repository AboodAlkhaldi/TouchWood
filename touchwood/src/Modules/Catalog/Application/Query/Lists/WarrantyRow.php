<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * A warranty (catalog.md §1.9, §4.4 S6): its period — none for life — its terms, and how many
 * products carry it.
 */
final readonly class WarrantyRow
{
    /**
     * @param  array<string, mixed>  $termsAr  the structured text (§1.1)
     * @param  array<string, mixed>  $termsEn
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public ?int $periodMonths,
        public array $termsAr,
        public array $termsEn,
        public bool $active,
        public int $products,
    ) {}
}
