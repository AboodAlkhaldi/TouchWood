<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store's card (frontend.md 3.5, E1 and E2).
 *
 * The tax rate travels twice: as the basis points Platform keeps (platform.md 1.1) and as the
 * percentage a person reads and types. Turning one into the other is done here, once, in integer
 * arithmetic - a rate kept as 1500 can never drift into 15.000000000000002 on its way to a screen.
 */
#[TypeScript]
final class StoreRow extends Data
{
    public function __construct(
        public string $id,
        /** The URL segment, and the store's name to the system: "sa". Never changes. */
        public string $code,
        /** Already in the language the panel is being read in. */
        public string $name,
        public string $nameAr,
        public string $nameEn,
        public string $countryCode,
        public string $currencyCode,
        public string $currencySymbol,
        public int $taxRateBasisPoints,
        /** The same rate as a person writes it: "15" or "15.5". */
        public string $taxRatePercent,
        public string $timezone,
        public int $position,
        /** Whether this reader may change this store. Asked of Access, store by store. */
        public bool $editable,
    ) {}
}
