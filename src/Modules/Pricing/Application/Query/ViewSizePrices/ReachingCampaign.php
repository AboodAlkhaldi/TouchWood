<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSizePrices;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * A campaign run pricing the size, running or scheduled (pricing.md §9.6 #7; "What Reaches It"): read from
 * the size's candidates, so it costs no query - its run's id (its name is Content's), its lines (`RETAIL`,
 * `WHOLESALE`, `BOTH`), what it makes this size cost, its dates (ISO 8601), its state (`SCHEDULED`,
 * `RUNNING`), and whether it is what one piece costs now. It never always wins.
 */
final readonly class ReachingCampaign
{
    public function __construct(
        public string $id,
        public string $state,
        public string $lines,
        public ?MoneyData $priceOnSize,
        public string $startsAt,
        public ?string $endsAt,
        public bool $winsNow,
    ) {}
}
