<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\CustomerPointsInStores;

use Modules\Loyalty\Application\Query\ExpiringNext;
use Shared\Domain\ValueObject\Money;

/**
 * A customer's points in one store, as the customer page's section shows them (loyalty.md §4.1 L5).
 * `mayOpen`: the store is among those the reader's L2 filter offers - an off store is not, for
 * anyone who cannot switch stores, though they still see its points here.
 */
final readonly class StorePoints
{
    public function __construct(
        public string $storeId,
        public string $storeCode,
        public bool $storeIsActive,
        public int $livePoints,
        public Money $worth,
        public ?ExpiringNext $expiringNext,
        public bool $mayOpen,
    ) {}
}
