<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use Shared\Domain\ValueObject\StoreId;

/**
 * One lock per store for every price change there (pricing.md §5): taken first, inside the change's
 * own transaction, so the rows a change reads are not changed under it - and two "always wins" on one
 * size can be refused, which no CHECK can see across rows.
 */
interface PricingLocks
{
    public function lockStore(StoreId $store): void;
}
