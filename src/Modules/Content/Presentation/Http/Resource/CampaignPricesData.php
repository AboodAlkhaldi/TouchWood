<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A run's prices as its tab edits them (content.md §1.7 #4): Retail, Wholesale or Both, its targets and the products
 * left out.
 */
#[TypeScript]
final class CampaignPricesData extends Data
{
    /**
     * @param  list<CampaignTargetData>  $targets
     * @param  list<CampaignTargetData>  $leftOut
     */
    public function __construct(
        public string $lines,
        public array $targets,
        public array $leftOut,
    ) {}
}
