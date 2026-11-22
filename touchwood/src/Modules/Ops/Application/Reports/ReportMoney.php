<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Shared\Domain\ValueObject\Money;

/** An amount in a report's cell, with its currency's decimals; the row names the currency beside it. */
final readonly class ReportMoney
{
    public function __construct(
        public Money $money,
        public int $exponent,
    ) {}

    /** "1234.50": Latin digits, no grouping. */
    public function decimal(): string
    {
        return $this->money->toDecimal($this->exponent);
    }
}
