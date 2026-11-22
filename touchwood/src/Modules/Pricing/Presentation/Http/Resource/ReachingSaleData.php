<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An offer reaching a size, running or scheduled (the drawer's What Reaches It).
 */
#[TypeScript]
final class ReachingSaleData extends Data
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
