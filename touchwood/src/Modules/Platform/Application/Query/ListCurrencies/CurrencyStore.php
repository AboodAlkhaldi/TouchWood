<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListCurrencies;

/**
 * A store that charges in a currency, named on the currencies screen (platform.md §9.7): on or off,
 * since an off store's currency is as fixed as any other's.
 */
final readonly class CurrencyStore
{
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public bool $isActive,
    ) {}
}
