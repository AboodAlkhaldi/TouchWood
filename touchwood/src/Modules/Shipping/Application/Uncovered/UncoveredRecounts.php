<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Uncovered;

use Shared\Domain\ValueObject\StoreId;

/**
 * Counting a store's uncovered places again, once the change that may move the count commits: every
 * change to its carriers or prices, and every ten minutes for every store - a place added or retired in
 * Platform, or a ship-to country added, tells Shipping nothing (§6), so the count follows within ten
 * minutes; the list itself is read live.
 */
interface UncoveredRecounts
{
    public function queue(StoreId $store): void;
}
