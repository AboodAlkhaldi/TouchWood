<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\RemovedLine;
use Shared\Domain\ValueObject\StoreId;

/**
 * The carts (sales.md §1.1, §5): one a buyer a store; every read names the store. A change takes the
 * cart's row lock first (`lock`, `lockOrCreate`), so two changes at once never lose one another.
 */
interface Carts
{
    /** The buyer's cart in the store, as it is; null when they have none. Exactly one of the two ids. */
    public function find(StoreId $store, ?string $customerId, ?string $guestId): ?Cart;

    /** The buyer's cart in the store, its row locked until the transaction ends; null when they have none. */
    public function lock(StoreId $store, ?string $customerId, ?string $guestId): ?Cart;

    /** The buyer's cart in the store, created when there is none, its row locked until the transaction ends. */
    public function lockOrCreate(StoreId $store, ?string $customerId, ?string $guestId, DateTimeImmutable $at): Cart;

    /**
     * Adds to a line — created at this quantity, added at `$addedAt` (now when none), when the cart has
     * none of that size and mode — at most `CartLine::MOST`.
     */
    public function add(string $cartId, string $variantId, SaleMode $mode, int $quantity, DateTimeImmutable $at, ?DateTimeImmutable $addedAt = null): void;

    /** Sets a line's quantity; false when the cart has no such line. */
    public function change(string $cartId, string $variantId, SaleMode $mode, int $quantity, DateTimeImmutable $at): bool;

    /** Nothing when the cart has no such line. */
    public function remove(string $cartId, string $variantId, SaleMode $mode, DateTimeImmutable $at): void;

    public function setCoupon(string $cartId, ?string $code, DateTimeImmutable $at): void;

    /** @param  list<RemovedLine>  $notice  none clears it */
    public function setNotice(string $cartId, array $notice, DateTimeImmutable $at): void;

    /**
     * A guest's carts in every store, their rows locked, in store order.
     *
     * @return list<Cart>
     */
    public function lockGuestCarts(string $guestId): array;

    public function delete(string $cartId): void;

    /** Every cart of the customer, in every store, their rows locked first. */
    public function deleteForCustomer(string $customerId): int;

    /** Carts no one changed since then: how many were emptied. */
    public function deleteUntouchedSince(DateTimeImmutable $before): int;
}
