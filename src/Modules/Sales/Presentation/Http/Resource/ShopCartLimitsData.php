<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How many pieces of a size one line in one mode may hold (sales.md §4.2 B15): `maximum` null when there is no most. */
#[TypeScript]
final class ShopCartLimitsData extends Data
{
    public function __construct(
        public int $minimum,
        public ?int $maximum,
    ) {}
}
