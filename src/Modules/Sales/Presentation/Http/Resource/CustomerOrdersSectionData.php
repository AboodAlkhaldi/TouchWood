<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The staff customer page's `sales.orders` section (sales.md §3.2, §4.1 P7): the customer's 10 latest orders the
 * reader may see, each as the orders list draws it, and the customer for "See all".
 */
#[TypeScript]
final class CustomerOrdersSectionData extends Data
{
    /**
     * @param  list<OrderRowData>  $orders
     */
    public function __construct(
        public string $customerId,
        public array $orders,
    ) {}
}
