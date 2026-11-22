<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A currency no store uses, offered to a new store (platform.md §9.7 #4).
 */
#[TypeScript]
final class FreeCurrencyRow extends Data
{
    public function __construct(
        public string $code,
        /** In the panel's language. */
        public string $name,
    ) {}
}
