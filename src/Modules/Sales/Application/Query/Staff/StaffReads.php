<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

/**
 * What staff read of a store's orders (sales.md §3: `sales.order.view`), in SQL behind this interface
 * (`Infrastructure/Eloquent/DatabaseStaffReads`). The caller has already narrowed the stores to those the
 * reader covers.
 */
interface StaffReads
{
    public const string ALL = 'ALL';

    /** Holding less than is due. */
    public const string UNPAID = 'UNPAID';

    /** Flagged short of stock. */
    public const string SHORT = 'SHORT';

    /** Waiting for staff's decision. */
    public const string WAITING_APPROVAL = 'WAITING_APPROVAL';

    /** Approved, not shipped. */
    public const string TO_SHIP = 'TO_SHIP';

    /** Shipped, not yet delivered nor come back. */
    public const string SHIPPED = 'SHIPPED';

    /** Paid by bank transfer, a document uploaded, still holding less than is due. */
    public const string TRANSFERS_TO_CHECK = 'TRANSFERS_TO_CHECK';

    public const array FILTERS = [self::ALL, self::UNPAID, self::SHORT, self::WAITING_APPROVAL, self::TO_SHIP, self::SHIPPED, self::TRANSFERS_TO_CHECK];

    /**
     * Orders of these stores (null: every store), newest first, after the order with that id; `$q` an order
     * number, a customer's name (as placed) or a phone's digits, any part of it; `$customerId` one customer's.
     *
     * @param  list<string>|null  $storeIds
     * @return list<array<string, mixed>>
     */
    public function orders(?array $storeIds, string $filter, ?string $afterOrderId, int $limit, ?string $q = null, ?string $customerId = null): array;

    /** @return array<string, mixed>|null */
    public function order(string $orderId): ?array;

    /**
     * The carriers the store's orders were shipped with, each once, the most lately shipped with first.
     *
     * @return list<string>
     */
    public function carriers(string $storeId, int $limit): array;

    /** @return list<array<string, mixed>> payments and refunds, oldest first, each with `kind` PAYMENT or REFUND */
    public function money(string $orderId): array;

    /** @return list<array<string, mixed>> the order's history, oldest first */
    public function history(string $orderId): array;

    /** @return list<array<string, mixed>> the order's edits, oldest first */
    public function edits(string $orderId): array;

    /** @return list<array<string, mixed>> the sizes ticked as reduced in the provider */
    public function ticks(string $orderId): array;

    /** @return list<array<string, mixed>> the transfer documents, in their order */
    public function transferDocuments(string $orderId): array;

    /**
     * Those of these stores holding an unfinished order (§1.12): one waiting or approved, one whose payment is
     * unpaid, partly paid or due a refund, or one with a return not yet completed, refused, withdrawn or closed.
     *
     * @param  list<string>  $storeIds
     * @return list<string>
     */
    public function storesWithUnfinished(array $storeIds): array;
}
