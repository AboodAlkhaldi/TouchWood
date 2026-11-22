<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Modules\Loyalty\Public\Enums\EntryKind;

/** One kind of ledger entry over a range in a store (`LoyaltyApi::pointsByKind`). */
final readonly class PointsByKindDto
{
    public function __construct(
        public EntryKind $kind,
        public int $entries,
        /** Their points together, signed as the entries are. */
        public int $points,
    ) {}
}
