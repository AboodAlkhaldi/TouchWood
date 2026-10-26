<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The staff customer page's `sales.orders` section (sales.md §3.2): the customer's 10 latest orders the reader may see. */
#[TypeScript]
final class CustomerOrdersSectionData extends Data
{
    /**
     * @param  list<CustomerOrderData>  $orders
     */
    public function __construct(
        public array $orders,
    ) {}
}
