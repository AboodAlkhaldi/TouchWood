<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RemoveVariantAttribute;

/**
 * An attribute a product's variants are made of, removed from its Variants tab (catalog.md §1.7,
 * P27, amendment 16(b)): each variant gives up its value of it.
 */
final readonly class RemoveVariantAttribute
{
    public function __construct(
        public string $productId,
        public string $attributeId,
    ) {}
}
