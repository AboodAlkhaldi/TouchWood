<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListCarriers;

/**
 * A store's carriers, by the countries it ships to (shipping.md §4.1, the Carriers screen).
 */
final readonly class ListCarriers
{
    public function __construct(
        public string $storeId,
        public string $locale,
    ) {}
}
