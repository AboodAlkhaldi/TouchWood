<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A country Add Country… may offer, named in the page's language (ops.md §4.15 O10). */
#[TypeScript]
final class SmsCountryOptionData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}
}
