<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One country on the SMS countries list (ops.md §3, §4; access.md amendment 90): its ISO code, its name in the page's language, its calling code, its provider. */
#[TypeScript]
final class SmsCountryData extends Data
{
    public function __construct(
        public string $countryCode,
        public string $name,
        public string $callingCode,
        public string $provider,
    ) {}
}
