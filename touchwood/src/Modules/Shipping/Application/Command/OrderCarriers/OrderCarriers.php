<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\OrderCarriers;

/**
 * A country's carriers in their new order, dragged or moved to the top or the bottom (shipping.md
 * §1.3; catalog.md amendment 16(d)): the ids in that order. Those not sent keep their order, after
 * them. Ordered within the country (step 3 Q5).
 */
final readonly class OrderCarriers
{
    /**
     * @param  list<string>  $carrierIds
     */
    public function __construct(
        public string $storeId,
        public string $countryCode,
        public array $carrierIds,
    ) {}
}
