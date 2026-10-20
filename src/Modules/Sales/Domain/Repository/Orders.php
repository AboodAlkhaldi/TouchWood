<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Model\PlacedOrder;

/** The orders (sales.md §5): written whole when placed, each change kept in their history. */
interface Orders
{
    /** The order placed from this quote, if one was. */
    public function placedFrom(string $quoteId): ?PlacedOrder;

    /** The order and its lines, in one go. */
    public function add(NewOrder $order): void;

    /** The order's machines, its row locked until the transaction ends — every change locks it first (§1.4). */
    public function lock(string $orderId): ?OrderState;

    /** The store an order belongs to; null when there is no such order. */
    public function storeOf(string $orderId): ?string;

    public function approve(string $orderId, string $by, DateTimeImmutable $at): void;

    /** Rejected, with the reason; nothing is due any more (§1.4). */
    public function reject(string $orderId, string $by, string $reason, DateTimeImmutable $at): void;

    /** Cancelled — by its customer, with a listed reason or other words, or by staff — nothing due any more. */
    public function cancel(string $orderId, string $reason, ?string $other, ?string $by, DateTimeImmutable $at): void;

    /**
     * "Stock was reduced" on those of the store's orders still open and unshipped, once (§1.11).
     *
     * @param  list<string>  $orderIds
     * @return list<string> the orders flagged now
     */
    public function flagShort(string $storeId, array $orderIds, DateTimeImmutable $at): array;

    /** The flag cleared; false when there was none. */
    public function clearShort(string $orderId): bool;

    /** An anonymized account's orders keep their name, phone and address, never their email (§1.12). */
    public function forgetEmail(string $customerId): void;

    /**
     * One change of the order, once, never changed (§5 `order_history`). Its detail never holds the
     * customer's email or phone: anonymizing could not clear it.
     *
     * @param  array<string, mixed>  $detail
     */
    public function recordHistory(string $orderId, string $storeId, string $kind, ?string $editId, ?string $by, DateTimeImmutable $at, array $detail): void;
}
