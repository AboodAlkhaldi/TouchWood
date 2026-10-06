<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\CreateStore;

use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;

/**
 * Every attribute at once: a store is never created half-configured (Platform spec §1.1).
 *
 * The currency is one no store uses (§1.2, §9.7 #4) - or, with $newCurrency, one made in the same
 * transaction, whose code is then the store's ($currencyCode is not read).
 */
final readonly class CreateStore
{
    public function __construct(
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public string $countryCode,
        public string $currencyCode,
        public int $taxRateBasisPoints,
        public string $timezone,
        public int $position,
        public ?CreateCurrency $newCurrency = null,
    ) {}
}
