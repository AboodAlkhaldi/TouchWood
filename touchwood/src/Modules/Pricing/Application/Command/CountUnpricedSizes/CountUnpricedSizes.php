<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\CountUnpricedSizes;

/**
 * A store's sizes that need a price counted again for the menu (pricing.md §1.9; owner Q43); every store
 * when none is named.
 */
final readonly class CountUnpricedSizes
{
    public function __construct(
        public ?string $storeId = null,
    ) {}
}
