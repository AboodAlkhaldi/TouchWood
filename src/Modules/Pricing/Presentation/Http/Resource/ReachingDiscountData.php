<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category discount reaching a size, running or scheduled (the drawer's What Reaches It).
 */
#[TypeScript]
final class ReachingDiscountData extends Data
{
    /**
     * @param  list<string>  $categoryPath
     */
    public function __construct(
        public string $id,
        public string $categoryId,
        public array $categoryPath,
        public string $state,
        public ?int $percentBasisPoints,
        public ?MoneyData $amountOff,
        public string $lines,
        public ?MoneyData $priceOnSize,
        public ?string $skipped,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public bool $winsNow,
    ) {}
}
