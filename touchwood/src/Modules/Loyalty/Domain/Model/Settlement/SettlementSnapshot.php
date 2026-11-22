<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

use DateTimeImmutable;
use Modules\Loyalty\Domain\Service\Settlement;

/**
 * What a settlement reads under the account's lock (loyalty.md §1.8): the order's facts and running
 * totals, its takes in the order spent, every lot of the account with its covers, the order's earned
 * lot, and the points that left that lot by expiry or by hand — points that came back to it expired
 * included.
 */
final readonly class SettlementSnapshot
{
    /**
     * @param  list<SettlementTake>  $orderTakes
     * @param  array<int, SettlementLot>  $lots
     */
    public function __construct(
        public OrderPointsFacts $facts,
        public array $orderTakes,
        public array $lots,
        public ?int $earnedLotId,
        public int $goneFromEarnedLot,
    ) {}

    public function settlement(DateTimeImmutable $now): Settlement
    {
        return new Settlement($this->facts, $this->orderTakes, $this->lots, $this->earnedLotId, $this->goneFromEarnedLot, $now);
    }
}
