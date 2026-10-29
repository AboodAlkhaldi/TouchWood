<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SwitchCarrier;

/**
 * A carrier on or off (shipping.md §1.3, §4). Off, it is in no new quote at once; orders already
 * placed keep the carrier and the fee they were placed with (owner, 2026-10-27 S16).
 */
final readonly class SwitchCarrier
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
        public bool $on,
    ) {}
}
