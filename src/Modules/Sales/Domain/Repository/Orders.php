<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\PlacedOrder;

/** The orders (sales.md §5): written whole when placed, each change kept in their history. */
interface Orders
{
    /** The order placed from this quote, if one was. */
    public function placedFrom(string $quoteId): ?PlacedOrder;

    /** The order and its lines, in one go. */
    public function add(NewOrder $order): void;

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
