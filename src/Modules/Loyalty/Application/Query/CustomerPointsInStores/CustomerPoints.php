<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\CustomerPointsInStores;

/**
 * The section's answer (loyalty.md §4.1 L5): a block per store where the customer has points and the
 * reader may read them, in the stores' order; and the store where the reader may give a first point,
 * if any (§9.5 P5) - by its code, for L2's address.
 */
final readonly class CustomerPoints
{
    /**
     * @param  list<StorePoints>  $stores
     */
    public function __construct(
        public array $stores,
        public ?string $giveStoreCode,
    ) {}
}
