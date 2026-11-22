<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A page of sizes (Catalog's keyset paging): the rows, the cursor for Load More, and how many in all. */
#[TypeScript]
final class PackingSizesData extends Data
{
    /**
     * @param  list<PackingSizeData>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
