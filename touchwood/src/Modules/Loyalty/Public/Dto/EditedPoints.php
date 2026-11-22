<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** An order's points after a staff edit (loyalty.md §1.8, §2.2): the points discount, and the points still charged. */
final readonly class EditedPoints
{
    public function __construct(
        public Money $discount,
        public int $pointsStillCharged,
    ) {}
}
