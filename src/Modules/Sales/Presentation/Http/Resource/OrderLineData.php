<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line of an order as staff see it (§3.2): the part's name in the page's language, its size, code, mode, pieces and prices. */
#[TypeScript]
final class OrderLineData extends Data
{
    public function __construct(
        public string $id,
        public string $variantId,
        public string $name,
        public string $values,
        public string $code,
        public string $mode,
        public bool $gift,
        public int $quantity,
        public MoneyData $unit,
        public MoneyData $listUnit,
        public MoneyData $total,
    ) {}
}
