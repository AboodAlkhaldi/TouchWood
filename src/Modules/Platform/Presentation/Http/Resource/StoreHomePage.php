<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F2 - a store's home (frontend.md 3.6).
 *
 * A placeholder until Content builds the real one (platform.md 3). It says which store the visitor
 * is in and what it charges in, which is the whole of what this stage promises.
 */
#[TypeScript]
final class StoreHomePage extends Data
{
    public function __construct(
        public string $name,
        public string $currency,
        public string $symbol,
    ) {}
}
