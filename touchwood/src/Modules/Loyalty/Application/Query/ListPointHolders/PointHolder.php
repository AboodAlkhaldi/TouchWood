<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ListPointHolders;

use DateTimeImmutable;
use Modules\Loyalty\Application\Query\CustomerName;
use Modules\Loyalty\Application\Query\ExpiringNext;
use Shared\Domain\ValueObject\Money;

/** One row of the store's points holders (loyalty.md §4.1 L1). */
final readonly class PointHolder
{
    public function __construct(
        public string $accountId,
        public CustomerName $customer,
        public int $livePoints,
        public Money $worth,
        public ?ExpiringNext $expiringNext,
        public ?DateTimeImmutable $lastAt,
    ) {}
}
