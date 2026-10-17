<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ViewCustomerPoints;

use DateTimeImmutable;

/** Staff reading a customer's points in a store (loyalty.md §3, "Customers › Loyalty"). */
final readonly class ViewCustomerPoints
{
    public function __construct(
        public string $customerId,
        public string $storeId,
        public ?DateTimeImmutable $beforeAt = null,
        public ?int $beforeId = null,
        public int $perPage = 50,
    ) {}
}
