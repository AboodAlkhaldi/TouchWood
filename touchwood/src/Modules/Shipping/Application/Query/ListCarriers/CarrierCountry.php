<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListCarriers;

use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Shipping\Application\Query\CarrierRow;

/**
 * One of the countries a store ships to, with its carriers in their order (shipping.md §4.1). `own`
 * marks the store's own country, listed first.
 */
final readonly class CarrierCountry
{
    /**
     * @param  list<CarrierRow>  $carriers
     */
    public function __construct(
        public string $code,
        public TranslatedTextDto $name,
        public bool $own,
        public array $carriers,
    ) {}
}
