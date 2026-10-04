<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ClearNotAvailableNow;

/**
 * Clears "Not available now" from a product, or one variant, in one store.
 */
final readonly class ClearNotAvailableNow
{
    public function __construct(
        public string $storeId,
        public string $productId,
        public ?string $variantId = null,
    ) {}
}
