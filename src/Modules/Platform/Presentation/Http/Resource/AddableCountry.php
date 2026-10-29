<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A country Add Country offers on E8 (platform.md §4.3, Q10): named in the page's language, and
 * found by its name in the other one too.
 */
#[TypeScript]
final class AddableCountry extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public string $otherName,
        /** The combobox's "ours first" group; none here: every country is offered alike. */
        public bool $ours = false,
    ) {}
}
