<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * L2 · a customer's points in one store (loyalty.md §4.1), and what this reader may do there.
 * `nextAt` and `nextId` are the history's next-page cursor, both null on its last page.
 */
#[TypeScript]
final class CustomerPointsPage extends Data
{
    /**
     * @param  list<LotData>  $lots  every live lot, soonest first
     * @param  list<HistoryRowData>  $history  newest first
     * @param  list<StoreOptionData>  $stores
     * @param  int  $mostByHand  the most points one change by hand may move
     */
    public function __construct(
        public CustomerNameData $customer,
        public int $points,
        public MoneyData $worth,
        public ?ExpiringData $expiring,
        public array $lots,
        public array $history,
        public ?string $nextAt,
        public ?int $nextId,
        public bool $mayChangePoints,
        public bool $mayOpenCustomer,
        public int $mostByHand,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public bool $programmeOn,
        public array $stores,
    ) {}
}
