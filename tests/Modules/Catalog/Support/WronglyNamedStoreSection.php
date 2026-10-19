<?php

declare(strict_types=1);

namespace Tests\Modules\Catalog\Support;

use Modules\Catalog\Public\Contracts\ProductStoreSection;

/**
 * A section naming its component as no path under resources/js/sections/ - one reaching outside it - so
 * the registry's refusal can be proved (catalog.md amendment 23(e)).
 */
final class WronglyNamedStoreSection implements ProductStoreSection
{
    public function component(): string
    {
        return '../Pricing/ProductPrices';
    }

    public function read(string $productId, array $variantIds, array $stores): array
    {
        return [];
    }
}
