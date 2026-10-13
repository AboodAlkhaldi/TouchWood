<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

use DateTimeImmutable;

/**
 * An order's hold (inventory.md §1.4, §5): one per order, in the order's store, with its lines keyed
 * by size and gift, the expiry Sales set, if any, the store's wiring when it was placed - which decides
 * what each line holds for the hold's whole life - and whether a cancel or the expiry ended it.
 */
final readonly class Hold
{
    /**
     * @param  array<string, HoldLine>  $lines  by `HoldLine::key()`
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $orderId,
        public ?DateTimeImmutable $expiresAt,
        public array $lines,
        public bool $wired = false,
        public bool $ended = false,
    ) {}
}
