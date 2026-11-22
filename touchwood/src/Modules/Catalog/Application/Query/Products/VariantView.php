<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * One variant as its product's Variants tab shows it (catalog.md §4.4 S9).
 */
final readonly class VariantView
{
    /**
     * @param  list<array{attributeId: string, valueId: string, nameAr: string, nameEn: string, swatch: string|null}>  $values
     * @param  list<array{attributeId: string, textAr: string|null, textEn: string|null, number: string|null}>  $details
     * @param  list<string>  $photos  media ids, in order
     */
    public function __construct(
        public string $id,
        public string $code,
        public int $position,
        public bool $archived,
        public array $values,
        public array $details,
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
        public array $photos,
    ) {}
}
