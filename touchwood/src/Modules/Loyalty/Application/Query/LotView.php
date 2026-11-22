<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;

/** A live lot as a screen shows it (loyalty.md §3): its points left and its date — no ids. */
final readonly class LotView
{
    public function __construct(
        public int $pointsLeft,
        public DateTimeImmutable $expiresAt,
    ) {}
}
