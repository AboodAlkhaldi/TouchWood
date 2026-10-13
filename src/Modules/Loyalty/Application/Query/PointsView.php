<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;
use Modules\Loyalty\Domain\Model\Lot;
use Shared\Domain\ValueObject\Money;

/**
 * A customer's points in one store (loyalty.md §3): the balance, what it is worth, each live lot and
 * its date, and a page of the history, newest first, with the cursor to the next page (null at the end).
 */
final readonly class PointsView
{
    /**
     * @param  list<Lot>  $lots  live, the one expiring first first
     * @param  list<PointsHistoryRow>  $history
     */
    public function __construct(
        public int $balance,
        public Money $worth,
        public array $lots,
        public array $history,
        public ?DateTimeImmutable $nextAt,
        public ?int $nextId,
    ) {}
}
