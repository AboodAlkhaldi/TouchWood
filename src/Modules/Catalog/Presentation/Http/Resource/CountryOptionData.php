<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A country the brand form's picker offers: its code and its name in the panel's language.
 */
#[TypeScript]
final class CountryOptionData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $ours,
    ) {}
}
