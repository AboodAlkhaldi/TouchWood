<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

/**
 * A cover on a reversed lot (loyalty.md §1.3): points it could not give back itself, covered by its
 * source; `undone` grows as points come back to the lot. `id` is null for a cover a settlement makes.
 */
final class SettlementCover
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $lotId,
        public readonly CoverSource $source,
        /** The COVER take, for a LOT cover. */
        public readonly ?SettlementTake $sourceTake,
        public readonly int $points,
        public int $undone = 0,
    ) {}

    public function open(): int
    {
        return $this->points - $this->undone;
    }
}
