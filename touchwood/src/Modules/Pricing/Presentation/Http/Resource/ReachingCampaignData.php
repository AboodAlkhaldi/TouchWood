<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A campaign run pricing a size, running or scheduled (the drawer's What Reaches It; pricing.md §9.6 #7).
 */
#[TypeScript]
final class ReachingCampaignData extends Data
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
