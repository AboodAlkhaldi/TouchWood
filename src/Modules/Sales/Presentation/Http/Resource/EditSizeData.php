<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A size Add Product finds (§4.1 P4; §4.2 B7): its product, values, today's prices and the stock available. */
#[TypeScript]
final class EditSizeData extends Data
{
    public function __construct(
        public string $variantId,
        public string $name,
        public string $values,
        public string $code,
        public ?MoneyData $retail,
        public ?MoneyData $retailBefore,
        public ?MoneyData $wholesale,
        public ?int $wholesaleMinimum,
        public ?int $available,
    ) {}
}
