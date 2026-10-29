<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ViewCarrier;

/**
 * One carrier's page (shipping.md §4.1): its details now; its price groups from step 4.
 */
final readonly class ViewCarrier
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
    ) {}
}
