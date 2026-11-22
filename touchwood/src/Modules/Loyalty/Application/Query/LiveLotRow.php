<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;

/**
 * A live lot of one of several accounts, read in one query for a page of them (loyalty.md §4.1):
 * what "expiring next" is worked out from.
 */
final readonly class LiveLotRow
{
    public function __construct(
        public string $accountId,
        public string $storeId,
        public int $pointsLeft,
        public DateTimeImmutable $expiresAt,
    ) {}
}
