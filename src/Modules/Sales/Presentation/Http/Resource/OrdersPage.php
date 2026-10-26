<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** P1 - the panel's orders (§4.1): one store or all the reader's, a tab's orders newest first, the next page's cursor. */
#[TypeScript]
final class OrdersPage extends Data
{
    /**
     * @param  list<OrderRowData>  $orders
     * @param  list<SalesStoreData>  $stores
     * @param  list<string>  $tabs
     */
    public function __construct(
        public array $orders,
        public ?string $next,
        public string $tab,
        public ?string $storeCode,
        public array $stores,
        public array $tabs,
    ) {}
}
