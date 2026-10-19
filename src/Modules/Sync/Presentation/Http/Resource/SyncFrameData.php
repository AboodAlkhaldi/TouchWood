<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What every Sync screen shows around its rows (sync.md §4.1; pricing.md §4.3.1): the store shown and the
 * filter's stores, whether it is off (a Super Admin's, marked Off) or wired, what the reader may do there
 * (`SyncActionsForReader`), whether a Sync Now read waits and so why Sync Now is disabled (Q26), and the
 * reasons a ⋯ item is out of reach.
 */
#[TypeScript]
final class SyncFrameData extends Data
{
    /**
     * @param  list<SyncStoreOptionData>  $stores
     */
    public function __construct(
        public string $storeId,
        public string $storeCode,
        public string $storeName,
        public array $stores,
        public bool $off,
        public bool $wired,
        public bool $mayView,
        public bool $mayRun,
        public bool $mayWire,
        public bool $mayViewProducts,
        public bool $mayViewOffers,
        public bool $nowWaiting,
        public ?string $syncNowRefusal,
        public SyncReasonsData $reasons,
    ) {}
}
