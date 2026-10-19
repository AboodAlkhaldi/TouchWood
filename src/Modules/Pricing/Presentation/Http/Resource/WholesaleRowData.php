<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of Wholesale Prices (P4).
 */
#[TypeScript]
final class WholesaleRowData extends Data
{
    /**
     * @param  list<BandData>  $bands
     */
    public function __construct(
        public SizeData $size,
        public ?RetailPriceData $retail,
        public array $bands,
        public ?int $minimum,
        public ?int $maximum,
        public bool $keptNotSoldWholesale,
        public bool $noRetailPrice,
        public bool $minimumChanged,
    ) {}
}
