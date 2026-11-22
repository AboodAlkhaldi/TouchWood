<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

/**
 * Points an order (REDEMPTION) or a cover (COVER) took from a lot, and how many have come back
 * (loyalty.md §1.3). Changed as a settlement is worked out; `id` is null for a take it makes.
 */
final class SettlementTake
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $lotId,
        public readonly int $points,
        public int $back = 0,
    ) {}

    public function out(): int
    {
        return $this->points - $this->back;
    }
}
