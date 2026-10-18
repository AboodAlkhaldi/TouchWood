<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A live lot (loyalty.md §4.1 L2, L7): the points it still holds, and the moment they expire. */
#[TypeScript]
final class LotData extends Data
{
    public function __construct(
        public int $points,
        public string $expiresAt,
    ) {}
}
