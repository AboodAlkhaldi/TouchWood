<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * L7 · the shop account's Points tab (loyalty.md §4.1). The "how it works" line (§9.5 P3) reads the
 * two rates: `earnPerUnit` points for every `unit` spent, and `pointsPerUnitOff` points for `unit`
 * off - `unit` being 1 of the store's currency.
 */
#[TypeScript]
final class MyPointsPage extends Data
{
    /**
     * @param  list<LotData>  $lots  every live lot, soonest first
     * @param  list<HistoryRowData>  $history  newest first, with no staff name and no reason
     */
    public function __construct(
        public int $points,
        public MoneyData $worth,
        public ?ExpiringData $expiring,
        public array $lots,
        public array $history,
        public ?string $nextAt,
        public ?int $nextId,
        public bool $programmeOn,
        public int $earnPerUnit,
        public int $pointsPerUnitOff,
        public MoneyData $unit,
        public string $timezone,
        /** Of `points`, those held for a payment in progress - shown only when there are some. */
        public int $held = 0,
    ) {}
}
