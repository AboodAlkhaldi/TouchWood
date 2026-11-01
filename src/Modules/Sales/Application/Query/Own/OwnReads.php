<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use DateTimeImmutable;

/**
 * What a customer reads of their own orders and returns (sales.md §3), in SQL behind this interface
 * (`Infrastructure/Eloquent/DatabaseOwnReads`). Every read is the customer's own: another's order or return is
 * no order, no return.
 */
interface OwnReads
{
    /**
     * The customer's orders in every store, newest first, after the order with that number.
     *
     * @return list<array{number: string, storeId: string, placedAt: DateTimeImmutable, status: string, shipment: string, currency: string, total: int, due: int, paid: int, refunded: int, pieces: int, shippingPending: bool}>
     */
    public function orders(string $customerId, ?string $afterNumber, int $limit): array;

    /**
     * One of the customer's orders by its number, every column a view needs; null when it is not theirs.
     *
     * @return array<string, mixed>|null
     */
    public function order(string $customerId, string $number): ?array;

    /**
     * @return list<array<string, mixed>> the order's lines, the placed parts first, then each edit's, the gift last
     */
    public function lines(string $orderId): array;

    /**
     * @return list<array<string, mixed>> the order's returns, newest first
     */
    public function returns(string $orderId): array;

    public function transferDocuments(string $orderId): int;

    /**
     * One of the customer's returns, with its order's number and currency; null when it is not theirs.
     *
     * @return array<string, mixed>|null
     */
    public function returnOf(string $customerId, string $returnId): ?array;

    /**
     * @return list<array<string, mixed>> the return's lines with the order lines' snapshots
     */
    public function returnLines(string $returnId): array;

    public function returnPhotos(string $returnId): int;
}
