<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListCurrencies;

/**
 * One currency, as the currencies screen shows it (frontend.md 3.5, E3).
 */
final readonly class CurrencySummary
{
    public function __construct(
        public string $code,
        public int $exponent,
        public string $nameAr,
        public string $nameEn,
        public string $abbreviationAr,
        public string $abbreviationEn,
        public ?string $sign,
        /** How many stores charge in it. */
        public int $storeCount,
        /**
         * Whether the number of decimal places is settled.
         *
         * Locked the moment any store charges in it (platform.md 1.2): changing it afterwards
         * would reinterpret every amount ever written in that currency - a price of 1000 means ten
         * riyals at two places and a thousand at none.
         */
        public bool $exponentLocked,
    ) {}
}
