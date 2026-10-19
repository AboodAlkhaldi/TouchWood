<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** L4 · Points › Redemptions (loyalty.md §4.1): newest first, the next page's cursor null on the last. */
#[TypeScript]
final class RedemptionsListPage extends Data
{
    /**
     * @param  list<RedemptionRowData>  $redemptions
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $redemptions,
        public ?string $nextAt,
        public ?int $nextId,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public bool $programmeOn,
        public array $stores,
    ) {}
}
