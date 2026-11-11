<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetPriceFromFile;

/**
 * A store file's price for the sizes an item switched on (pricing.md §1.8): queued by Pricing's part of
 * the file once Catalog's change commits, run by the system for whoever switched it on.
 */
final readonly class SetPriceFromFile
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $storeId,
        public array $variantIds,
        public string $price,
    ) {}
}
