<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * L1 · Points › Customers (loyalty.md §4.1): the store's points holders, most live points first.
 * `nextPoints` and `nextAccountId` are the cursor of the next page, both null on the last;
 * `tooMany`: the search matched so many customers that it asks for more of the text (§9.5 P2).
 */
#[TypeScript]
final class PointHoldersPage extends Data
{
    /**
     * @param  list<PointHolderData>  $holders
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $holders,
        public ?int $nextPoints,
        public ?string $nextAccountId,
        public bool $tooMany,
        public ?string $search,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public bool $programmeOn,
        public array $stores,
    ) {}
}
