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

    /**
     * Orders of these stores (null: every store), newest first, after the order with that id.
     *
     * @param  list<string>|null  $storeIds
     * @return list<array<string, mixed>>
     */
    public function orders(?array $storeIds, string $filter, ?string $afterOrderId, int $limit): array;

    /** @return array<string, mixed>|null */
    public function order(string $orderId): ?array;

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
