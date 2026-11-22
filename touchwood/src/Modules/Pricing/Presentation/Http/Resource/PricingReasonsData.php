<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why a row's button is drawn disabled (pricing.md §4.3.1), in the page's language -
 * `PricingActionsForReader::reasons()`.
 */
#[TypeScript]
final class PricingReasonsData extends Data
{
    public function __construct(
        public string $noPrice,
        public string $notSoldWholesale,
        public string $archived,
        public string $wiredPrice,
    ) {}
}
