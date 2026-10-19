<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSizePrices;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * A sale reaching the size, running or scheduled (pricing.md §4.3.3 "What Reaches It"): a percentage (in
 * basis points) or a sale price; what it makes this size cost (none where a percentage would leave 0, or
 * the size has no price); its dates (ISO 8601); its state (`SCHEDULED`, `RUNNING`); "always wins"; and
 * whether it is what one piece costs now.
 */
final readonly class ReachingSale
{
    public function __construct(
        public string $id,
        public string $state,
        public ?int $percentBasisPoints,
        public ?MoneyData $salePrice,
        public ?MoneyData $priceOnSize,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public bool $winsNow,
    ) {}
}
