<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * What one piece of a line costs (pricing.md §1.6): the line's normal price (`listUnit` - the retail
 * price; on a wholesale line its band where one applies), what it costs now (`unit`), the kind that
 * won, and when that price stops applying (null when nothing ends it) - and, for the screens (stage 5's
 * screens step 2), whether the winner is an "always wins" one and the sale or discount it comes from.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public Money $listUnit,
        public Money $unit,
        public PriceKind $kind,
        public ?DateTimeImmutable $endsAt,
        public bool $alwaysWins = false,
        public ?string $sourceId = null,
    ) {}
}
