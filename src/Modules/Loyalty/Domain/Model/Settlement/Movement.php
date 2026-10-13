<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

use Modules\Loyalty\Public\Enums\EntryKind;

/**
 * Points onto (+) or off (−) one lot, under the entry of its kind (loyalty.md §1.2): given back
 * (REDEMPTION_RETURNED), taken back (EARNING_REVERSED), or expired at once on a lot whose date passed.
 */
final readonly class Movement
{
    public function __construct(
        public int $lotId,
        public int $points,
        public EntryKind $kind,
    ) {}
}
