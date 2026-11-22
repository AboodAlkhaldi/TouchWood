<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The customer page's Points section (loyalty.md §4.1 L5; access.md amendment 72(a)): a block per
 * store, and where Give Points… leads, if anywhere (§9.5 P5).
 */
#[TypeScript]
final class PointsSectionData extends Data
{
    /**
     * @param  list<StorePointsData>  $stores
     */
    public function __construct(
        public string $customerId,
        public array $stores,
        public ?string $giveStoreCode,
    ) {}
}
