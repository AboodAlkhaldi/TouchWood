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
}
