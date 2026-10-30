<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Shared\Domain\ValueObject\Money;

/** The all-stores report's totals in one currency (ops.md §1.10: one total per currency, never converted). */
final readonly class CurrencyTotals
{
    /**
     * @param  list<string>  $storeIds  the stores counted in it
     */
    public function __construct(
        public string $currencyCode,
        public int $currencyExponent,
        /** The currency's letters in each language, as its row names them. */
        public string $symbolAr,
        public string $symbolEn,
        public array $storeIds,
        public PeriodFigures $today,
        public Money $vat,
        public Money $shipping,
        public Money $refunds,
        public Money $paymentsIn,
        public PeriodFigures $monthToDate,
    ) {}
}
