<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * One price a line could take (pricing.md §1.6): the retail price, a sale, a category discount or, on a
 * wholesale line, a quantity band - already worked out (a percentage applied, a category expanded), as
 * the materialized candidates hold it (§5). A sale or a discount has a window; the retail price and a
 * band have none.
 */
final readonly class Candidate
{
    public function __construct(
        public SaleMode $mode,
        public int $fromQuantity,
        public Money $amount,
        public PriceKind $kind,
        public bool $alwaysWins = false,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?string $sourceId = null,
    ) {}

    /** It applies at this moment: started (or never dated) and not yet ended. */
    public function runningAt(DateTimeImmutable $at): bool
    {
        return ($this->startsAt === null || $this->startsAt <= $at)
            && ($this->endsAt === null || $this->endsAt > $at);
    }

    /** Scheduled: it starts after this moment. */
    public function startsAfter(DateTimeImmutable $at): bool
    {
        return $this->startsAt !== null && $this->startsAt > $at;
    }
}
