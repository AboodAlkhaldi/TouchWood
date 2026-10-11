<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddVariantAttribute;

/**
 * An attribute a product's variants are made of, added from its Variants tab (catalog.md §1.7, P27,
 * amendment 16(b)): every variant, archived ones too, given its value of it in the same step.
 */
final readonly class AddVariantAttribute
{
    /**
     * @param  array<array-key, mixed>  $values  variant id => the value id it takes
     */
    public function __construct(
        public string $productId,
        public string $attributeId,
        public array $values,
    ) {}
}
