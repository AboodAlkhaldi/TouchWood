<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** P5 - the panel's returns (§4.1): one store or all the reader's, a tab's returns newest asked first, the next page's cursor. */
#[TypeScript]
final class ReturnsPage extends Data
{
    /**
     * @param  list<ReturnRowData>  $returns
     * @param  list<SalesStoreData>  $stores
     * @param  list<string>  $tabs
     */
    public function __construct(
        public array $returns,
        public ?string $next,
        public string $tab,
        public ?string $storeCode,
        public array $stores,
        public array $tabs,
    ) {}
}
