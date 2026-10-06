<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UpdateVariant;

/**
 * A variant's form, sent whole (catalog.md §1.2). Its code changes here only while the product is
 * a draft (amendment 3(c)); once ready, through `CorrectVariantCode`.
 */
final readonly class UpdateVariant
{
    /**
     * @param  array<array-key, mixed>  $values  attribute id => value id
     * @param  array<array-key, mixed>  $details  attribute id => ['text_ar' => …, 'text_en' => …] or ['number' => …]
     */
    public function __construct(
        public string $variantId,
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
