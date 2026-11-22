<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A size the offer dialog lists, with its retail price (none: not chosen).
 */
#[TypeScript]
final class SaleSizeChoiceData extends Data
{
    public function __construct(
        public string $variantId,
        public string $name,
        public string $code,
        public ?MoneyData $retail,
    ) {}
}
