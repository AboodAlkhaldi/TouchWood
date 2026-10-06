<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddVariant;

/**
 * A new variant of a product (catalog.md §1.2): its code, one value of every attribute of the
 * product's set, its details, its measures and its place among the product's variants.
 */
final readonly class AddVariant
{
    /**
     * @param  array<array-key, mixed>  $values  attribute id => value id
     * @param  array<array-key, mixed>  $details  attribute id => ['text_ar' => …, 'text_en' => …] or ['number' => …]
     */
    public function __construct(
        public string $productId,
        public string $code,
        public array $values = [],
        public array $details = [],
        public ?int $weightGrams = null,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
        public int $position = 0,
    ) {}
}
