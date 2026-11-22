<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One country to shop in (frontend.md 3.6, F1).
 */
#[TypeScript]
final class StoreChoiceRow extends Data
{
    public function __construct(
        public string $code,
        /** Already in the language the page is being read in. */
        public string $name,
        public string $countryCode,
        public string $currency,
        public string $symbol,
        /** Where choosing it leads. */
        public string $href,
    ) {}
}
