<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;

/**
 * One points account in a store, as the store's holders list reads it (loyalty.md §4.1 L1): its live
 * points - the live lots' sum, never the stored balance, which still holds points past their date
 * until the next write or the nightly sweep (§1.1) - and when it last moved.
 */
final readonly class HolderRow
{
    public function __construct(
        public string $accountId,
        public string $customerId,
        public int $livePoints,
        public ?DateTimeImmutable $lastAt,
    ) {}
}
