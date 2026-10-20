<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\EditedTotals;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\NewOrderLine;
use Modules\Sales\Domain\Model\OrderForEdit;
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

    /** What the order was paid and refunded moved by these amounts (§1.9) — a void moves them back. */
    public function moveMoney(string $orderId, int $paid, int $refunded): void;

    /** Whether staff let the order ship before what it holds covers what is due (§1.4). */
    public function allowShipUnpaid(string $orderId, bool $allow): void;

    /**
     * The bank-transfer documents the order holds, by their media ids, in their order (§1.9).
     *
     * @return list<string>
     */
    public function transferDocuments(string $orderId): array;

    public function addTransferDocument(string $orderId, string $storeId, string $mediaId, int $position, DateTimeImmutable $at): void;

    /**
     * The order's pieces as they stand, a line a size and gift — what a shipment takes (§1.5).
     *
     * @return list<array{variantId: string, quantity: int, gift: bool}>
     */
    public function pieces(string $orderId): array;

    public function ship(string $orderId, string $carrier, ?string $trackingNumber, DateTimeImmutable $at): void;

    /** Delivered: completed, its return window fixed to the minute (answer 34). */
    public function deliver(string $orderId, DateTimeImmutable $at, DateTimeImmutable $returnDeadline): void;

    /** The parcel came back: undelivered, nothing due any more (§1.5). */
    public function cameBack(string $orderId, string $cameBackId, DateTimeImmutable $at): void;

    /**
     * The sizes with a line counting on the store's stock as placed — the gift's included (§1.5).
     *
     * @return list<string>
     */
    public function countingOnStock(string $orderId): array;

    /**
     * The sizes ticked "reduced in the provider" (§1.5).
     *
     * @return list<string>
     */
    public function ticked(string $orderId): array;

    /** @param  list<string>  $variantIds  ticked once each */
    public function tick(string $orderId, string $storeId, array $variantIds, string $by, DateTimeImmutable $at): void;

    /** What an edit reads of the order (§1.7); null when there is no such order. */
    public function forEdit(string $orderId): ?OrderForEdit;

    /**
     * A staff edit kept (§5 `order_edits`): its own id, the reason, who and when, the order before and after
     * — lines and totals, never an email or a phone.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function addEdit(string $editId, string $orderId, string $storeId, string $reason, string $by, DateTimeImmutable $at, array $before, array $after): void;

    /**
     * The order's lines as the edit leaves them, every share allocated again: those not given go.
     *
     * @param  list<NewOrderLine>  $lines
     */
    public function replaceLines(string $orderId, string $storeId, array $lines): void;

    /** The totals, the gift and — when it changed — the address an edit left; what is due follows. */
    public function changeTotals(string $orderId, EditedTotals $totals): void;

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
