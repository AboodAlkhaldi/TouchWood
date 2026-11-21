<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a home section shows for one store (dashboard.md §3.2, D8): a chart, a ring or the split bars, a table.
 * All Stores gives one panel per store, shown one at a time behind small store tabs, the base store first,
 * because one axis cannot hold three currencies.
 */
#[TypeScript]
final readonly class HomeStorePanel
{
    /**
     * @param  list<HomeSplit>  $splits
     */
    public function __construct(
        public string $storeCode,
        /** Already in the reader's language. */
        public string $storeName,
        public ?HomeSeries $series = null,
        public array $splits = [],
        public ?HomeTable $table = null,
    ) {}
}
