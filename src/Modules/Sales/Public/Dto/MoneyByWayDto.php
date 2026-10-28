<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** The money one way moved over some days (`SalesReports::moneyOn`). */
final readonly class MoneyByWayDto
{
    public function __construct(
        /** `BANK_TRANSFER`, `ONLINE`, `OTHER`. */
        public string $way,
        public int $records,
        public Money $amount,
    ) {}
}
