<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store that charges in a currency, named on its card (frontend.md 3.5, E3; platform.md §9.7).
 */
#[TypeScript]
final class CurrencyStoreRow extends Data
{
    public function __construct(
        /** Already in the language the panel is being read in. */
        public string $name,
        /** An off store is named too, marked Off: its currency is as fixed as any other's. */
        public bool $isActive,
    ) {}
}
