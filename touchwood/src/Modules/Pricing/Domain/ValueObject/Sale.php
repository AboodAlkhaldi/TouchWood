<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * A sale (pricing.md §1.3): a new price or a percentage off, for the sizes it covers - one, some, or
 * every size of a product when it was made - from its start, until its end or for good. Its life is
 * read from the dates and the clock, never stored (§4.1).
 */
final readonly class Sale
{
    use DatedWindow;

    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $productId,
        public ?int $percentBasisPoints,
        public ?Money $price,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $alwaysWins,
        public array $variantIds,
        /** When it was added - kept, never changed; read back for the screens' **Newest Added** (Q7). */
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    /**
     * How deep it goes on a size, in basis points of the size's retail price: a percentage as it is; a
     * price by how far below the retail price it is.
     */
    public function depthOn(Money $retail): int
    {
        if ($this->percentBasisPoints !== null) {
            return $this->percentBasisPoints;
        }

        $price = $this->price ?? $retail;

        return $retail->minorUnits <= 0 ? 0 : (int) ceil(($retail->minorUnits - $price->minorUnits) * 10000 / $retail->minorUnits);
    }
}
