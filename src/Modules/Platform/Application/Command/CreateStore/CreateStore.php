<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\CreateStore;

/**
 * Every attribute at once: a store is never created half-configured (Platform spec §1.1).
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
    ) {}
}
