<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A country a new store may be in, as the shared country picker reads one.
 */
#[TypeScript]
final class StoreCountryOption extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        /** A country we already have a store in: listed first. */
        public bool $ours,
    ) {}
}
