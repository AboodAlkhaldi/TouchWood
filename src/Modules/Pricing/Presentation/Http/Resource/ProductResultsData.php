<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A page of products a picker found.
 */
#[TypeScript]
final class ProductResultsData extends Data
{
    /**
     * @param  list<PickedProductData>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
