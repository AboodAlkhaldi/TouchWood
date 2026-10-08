<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * A variant's stock in one store, as Sales needs it (inventory.md §2.2). `available` is how many can be
 * ordered now - in stock minus held - and null where stock does not limit ordering (an ordinary
 * product of a wired store). `availableAsGift` is how many can be given as a gift: a gift counts on
 * stock in every store (handoff §11.6), so it is always a number - in stock minus held - even where
 * `available` is null. Never shown to a customer as a number.
 */
final readonly class StockDto
{
    public function __construct(
        public string $variantId,
        public bool $countsOnStock,
        public bool $orderable,
        public ?int $available,
        public int $availableAsGift,
        public bool $endingSoon,
    ) {}
}
