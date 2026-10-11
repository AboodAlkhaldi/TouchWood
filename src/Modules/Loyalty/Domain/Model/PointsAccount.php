<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model;

use Shared\Domain\ValueObject\StoreId;

/**
 * A customer's points in one store (loyalty.md §1.1): one store's points never spend in another. The balance
 * is the stored one — the sum of the ledger, never below 0 — which can still hold points whose date
 * has passed until a write expires them (§1.10); what a customer can use now is the live lots' sum.
 */
final readonly class PointsAccount
{
    public function __construct(
        public string $id,
        public string $customerId,
        public StoreId $store,
        public int $balance,
    ) {}
}
