<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Contracts;

use DateTimeImmutable;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Dto\StockDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Inventory (inventory.md §2.1). Module to module, so no permission is
 * checked here - the calling use case checks its own.
 *
 * Stock is held when an order is placed and taken off when it ships; a cancel frees it (owner,
 * 2026-10-07). In a store with no provider every product counts on its stock; in a wired one only
 * stock-dependent products and gifts do, and a stock-dependent line's hold ends when staff tick
 * "reduced in the provider" (§1.2, §1.3). Refusals arrive as `Shared\Domain\Error\DomainError` with a
 * stable `type()` key.
 */
interface InventoryApi
{
    /**
     * For each variant in the store: whether its stock limits ordering here, whether it can be ordered
     * now, how many can be (null where stock does not limit), and whether it is "ending soon". Never
     * shown to a customer as a number (§1.9).
     *
     * @param  list<string>  $variantIds
     * @return array<string, StockDto> by variant id
     */
    public function stock(StoreId $store, array $variantIds): array;

    /**
     * Holds every line that counts on stock, all or nothing: one line short and nothing is held
     * (`inventory.not_enough_stock`, its context naming the variants and what is available). Lines
     * whose stock does not limit ordering are noted, not held. One hold per order
     * (`inventory.already_held`). After `expiresAt`, if given, a scheduled job frees it.
     *
     * @param  list<HoldLineDto>  $lines
     */
    public function hold(StoreId $store, string $orderId, array $lines, ?DateTimeImmutable $expiresAt): void;

    /**
     * The shipped pieces leave the stock and the hold - in a store with no provider. In a wired store
     * nothing is taken (the provider is the source); a stock-dependent line's hold waits for the tick.
     * Safe to repeat: what is already shipped is left as it is.
     *
     * @param  list<HoldLineDto>  $lines
     */
    public function ship(string $orderId, array $lines): void;

    /**
     * Staff ticked "reduced in the provider" for these lines (a wired store): their holds end. Safe to
     * repeat.
     *
     * @param  list<string>  $variantIds
     */
    public function reducedInProvider(string $orderId, array $variantIds): void;

    /**
     * Frees whatever the order still holds - a cancel, or the hold's expiry. Safe to repeat.
     */
    public function release(string $orderId): void;
}
