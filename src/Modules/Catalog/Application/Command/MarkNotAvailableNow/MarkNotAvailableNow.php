<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MarkNotAvailableNow;

/**
 * Marks a product, or one variant, "Not available now" in one store.
 */
final readonly class MarkNotAvailableNow
{
    public function __construct(
        public string $storeId,
        public string $productId,
        public ?string $variantId = null,
    ) {}
}
