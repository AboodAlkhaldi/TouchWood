<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A country a store ships to, on its card (platform.md §4.3, E1 addition).
 */
#[TypeScript]
final class ShipToRow extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        /** The store's own country: always shipped to, never removed. */
        public bool $own,
    ) {}
}
