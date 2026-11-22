<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What every Inventory screen shows around its rows (inventory.md §4.3.1, pricing.md §4.3.1): the store
 * shown and the filter's stores, whether it is off (a Super Admin's, marked Off) or wired to a provider
 * (the Note, no counting), whether the reader may open a product's page, the store's default threshold
 * ("Empty uses the store's default, 10."), and the reasons a control is out of reach.
 */
#[TypeScript]
final class InventoryFrameData extends Data
{
    /**
     * @param  list<InventoryStoreOptionData>  $stores
     */
    public function __construct(
        public string $storeCode,
        public string $storeName,
        public array $stores,
        public bool $off,
        public bool $wired,
        public bool $mayViewProducts,
        public int $defaultThreshold,
        public InventoryReasonsData $reasons,
    ) {}
}
