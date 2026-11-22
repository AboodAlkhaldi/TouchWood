<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

use DateTimeImmutable;

/**
 * A lot as a settlement sees it (loyalty.md §1.3): its points left, its date, the order it was earned
 * on, and its covers oldest first. Changed as the settlement is worked out.
 */
final class SettlementLot
{
    /**
     * @param  list<SettlementCover>  $covers  oldest first
     */
    public function __construct(
        public readonly int $id,
        public int $pointsLeft,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?string $orderId,
        public array $covers = [],
    ) {}

    public function isLiveAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }

    /**
     * The covers points coming back undo, in the spec's order (§4): lot covers oldest first, then
     * covers by expired points and drops, oldest first.
     *
     * @return list<SettlementCover>
     */
    public function coversToUndo(): array
    {
        $lot = array_filter($this->covers, static fn (SettlementCover $cover): bool => $cover->source === CoverSource::Lot);
        $rest = array_filter($this->covers, static fn (SettlementCover $cover): bool => $cover->source !== CoverSource::Lot);

        return [...array_values($lot), ...array_values($rest)];
    }
}
