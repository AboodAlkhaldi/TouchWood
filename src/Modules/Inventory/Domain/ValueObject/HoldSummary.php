<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * **What orders hold of a size in a store** (inventory.md §4.3.3, Q20, Q21): how many orders, and how
 * many pieces - bought and given as a gift apart - counted as the size's `held` counts them: each open
 * line that counts on stock, all of it in a wired store until its tick, otherwise what is not shipped.
 */
final readonly class HoldSummary
{
    public function __construct(
        public int $orders,
        public int $bought,
        public int $gift,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function pieces(): int
    {
        return $this->bought + $this->gift;
    }
}
