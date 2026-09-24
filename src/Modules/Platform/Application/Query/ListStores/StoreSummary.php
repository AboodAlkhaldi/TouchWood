<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListStores;

/**
 * One store, as the stores screen shows it (frontend.md §3.5, E1).
 *
 * The tax rate travels as basis points, the way Platform keeps it (platform.md §1.1). A percentage
 * is what a person types and reads, and turning one into the other is the screen's job: a rate
 * kept as 1500 can never drift into 15.000000000000002.
 */
final readonly class StoreSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public string $countryCode,
        public string $currencyCode,
        public string $currencySymbol,
        public int $taxRateBasisPoints,
        public string $timezone,
        public int $position,
        /** Whether this reader may change this store, which is asked store by store. */
        public bool $editable,
    ) {}
}
