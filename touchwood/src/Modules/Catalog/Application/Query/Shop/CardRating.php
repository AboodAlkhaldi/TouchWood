<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A card's stars (catalog.md amendment 25; feedback.md §1.2): the average of the product's approved
 * reviews to one decimal, half up, and how many there are — shown only where the store's lists show
 * ratings, and only once there is a review.
 */
final readonly class CardRating
{
    public function __construct(
        public float $average,
        public int $count,
    ) {}

    /** From the pushed sum and count: tenths rounded half up in whole numbers, so 4.25 reads 4.3. */
    public static function of(int $sum, int $count): self
    {
        return new self(intdiv(20 * $sum + $count, 2 * $count) / 10, $count);
    }
}
