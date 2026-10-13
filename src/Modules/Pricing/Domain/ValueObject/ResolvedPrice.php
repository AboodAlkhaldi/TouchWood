<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * What one piece of a line costs (pricing.md §1.6): the line's normal price (`listUnit` - the retail
 * price; on a wholesale line its band where one applies), what it costs now (`unit`), the kind that
 * won, and when that price stops applying (null when nothing ends it).
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public Money $listUnit,
        public Money $unit,
        public PriceKind $kind,
        public ?DateTimeImmutable $endsAt,
    ) {}
}
