<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\OrderFact;

/** Placed orders' facts (promotions.md §1.5, §1.6): written once, never changed. */
interface OrderFacts
{
    public function of(string $orderId): ?OrderFact;

    /**
     * Records the order's fact unless one is already there — the first wins, whoever writes it at the
     * same moment — and answers what is there afterwards.
     */
    public function record(string $orderId, string $storeId, string $customerId, DateTimeImmutable $placedAt, int $ceilingPercent): OrderFact;

    /**
     * Which of these customers ever placed an order in the store (§1.5: every placed order counts).
     *
     * @param  list<string>  $customerIds
     * @return list<string>
     */
    public function whoOrdered(string $storeId, array $customerIds): array;

    /**
     * The customers whose last order in the store was placed before the cutoff — keyset by id.
     *
     * @return list<string>
     */
    public function lastOrderBefore(string $storeId, DateTimeImmutable $cutoff, ?string $after, int $limit): array;

    /**
     * The customers with at least `$orders` orders in the store placed since `$since` — keyset by id.
     *
     * @return list<string>
     */
    public function atLeastOrdersSince(string $storeId, DateTimeImmutable $since, int $orders, ?string $after, int $limit): array;
}
