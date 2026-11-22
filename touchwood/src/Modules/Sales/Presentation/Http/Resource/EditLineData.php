<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line of the order being edited (§4.1 P4): its size and mode, pieces and price; a ticked one stays as it is (§1.7). */
#[TypeScript]
final class EditLineData extends Data
{
    public function __construct(
        public string $variantId,
        public string $mode,
        public string $name,
        public string $values,
        public string $code,
        public int $quantity,
        public MoneyData $unit,
        public bool $ticked,
    ) {}
}
