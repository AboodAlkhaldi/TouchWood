<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * A variant's stock in one store, as Sales needs it (inventory.md §2.2). `available` is how many can be
 * ordered now - in stock minus held - and null where stock does not limit ordering (an ordinary
 * product of a wired store). Never shown to a customer as a number.
 */
final readonly class StockDto
{
    public function __construct(
        public string $variantId,
        public bool $countsOnStock,
        public bool $orderable,
        public ?int $available,
        public bool $endingSoon,
    ) {}
}
