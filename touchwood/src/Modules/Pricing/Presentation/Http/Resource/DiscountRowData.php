<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One category discount (P3).
 */
#[TypeScript]
final class DiscountRowData extends Data
{
    /**
     * @param  list<string>  $categoryPath
     * @param  list<string>  $leftOut
     * @param  list<string>  $leftOutNames
     */
    public function __construct(
        public string $id,
        public string $categoryId,
        public array $categoryPath,
        public ?int $percentBasisPoints,
        public ?MoneyData $amountOff,
        public string $lines,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public string $state,
        public array $leftOut,
        public array $leftOutNames,
    ) {}
}
