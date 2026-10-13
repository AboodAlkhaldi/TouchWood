<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model;

use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;

/**
 * A redemption worked out (loyalty.md §1.7): the mode, the most points usable now, and either the
 * points charged with the discount they buy, or why none can be used.
 */
final readonly class Redemption
{
    public function __construct(
        public RedemptionMode $mode,
        public int $mostUsable,
        public int $pointsCharged,
        public int $discountMinor,
        public ?RedemptionRefusal $refusal,
    ) {}

    public static function refused(RedemptionMode $mode, int $mostUsable, RedemptionRefusal $refusal): self
    {
        return new self($mode, $mostUsable, 0, 0, $refusal);
    }
}
