<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** S7 - My Orders (sales.md §4.1): newest first, 20 then Show More. */
#[TypeScript]
final class MyOrdersPage extends Data
{
    /**
     * @param  list<OwnOrderRowData>  $orders
     */
    public function __construct(
        public array $orders,
        public ?string $next,
    ) {}
}
