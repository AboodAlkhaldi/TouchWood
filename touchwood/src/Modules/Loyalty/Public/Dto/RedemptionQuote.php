<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Shared\Domain\ValueObject\Money;

/**
 * Loyalty's answer to a quote (loyalty.md §1.7 step 6, §2.2). Checkout draws its points control from
 * it: the mode, the most points usable now, and — unless refused — the points charged and the
 * discount they buy. Refused: nothing charged, a discount of 0, and the reason.
 */
final readonly class RedemptionQuote
{
    public function __construct(
        public RedemptionMode $mode,
        public int $mostUsable,
        public int $pointsCharged,
        public Money $discount,
        public ?RedemptionRefusal $refusal,
    ) {}
}
