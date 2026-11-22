<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of Prices (P1): the size, its retail price, today's price, its wholesale bands and marks, and
 * its first band (Q8's warning).
 */
#[TypeScript]
final class PriceRowData extends Data
{
    public function __construct(
        public SizeData $size,
        public ?RetailPriceData $retail,
        public ?TodayPriceData $today,
        public bool $wholesaleOnly,
        public int $bands,
        public bool $keptNotSoldWholesale,
        public ?BandData $firstBand,
        public bool $soldWholesale,
    ) {}
}
