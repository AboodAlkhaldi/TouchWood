<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The bell's reach for a notice or a message to every customer of a store (ops.md §4.1): their email goes to fewer, those
 * who agreed, and that number is not given. A send by email alone is worded apart (§4.15, O4).
 */
#[TypeScript]
final class StoreAudienceData extends Data
{
    public function __construct(
        public int $count,
    ) {}
}
