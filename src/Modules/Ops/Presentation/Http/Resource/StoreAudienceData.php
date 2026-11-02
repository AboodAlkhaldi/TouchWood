<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How many customers a notice or a message to every customer of a store would reach (ops.md §4.1). */
#[TypeScript]
final class StoreAudienceData extends Data
{
    public function __construct(
        public int $count,
    ) {}
}
