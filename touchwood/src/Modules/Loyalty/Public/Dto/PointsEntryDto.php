<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use DateTimeImmutable;
use Modules\Loyalty\Public\Enums\EntryKind;

/** One ledger entry as Ops' message about it needs it (`LoyaltyApi::entryForMessage`). */
final readonly class PointsEntryDto
{
    public function __construct(
        public int $entryId,
        public string $storeId,
        public string $customerId,
        public EntryKind $kind,
        /** Signed: what the entry added (above 0) or took (below 0). */
        public int $points,
        /** The admin's words for an entry by hand; null otherwise. */
        public ?string $reason,
        /** The order's public number for an entry of an order; null otherwise. */
        public ?string $orderNumber,
        /** When the points it added expire (EARNED, ADDED); null for an entry that added none. */
        public ?DateTimeImmutable $expiresAt,
        /** The account's balance now: its live lots only, as `balance` counts it. */
        public int $balance,
    ) {}
}
