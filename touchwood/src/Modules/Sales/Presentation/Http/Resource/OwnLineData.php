<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line of the customer's order (§4.1 S8): the gift last at 0.00; how many pieces may still be returned. */
#[TypeScript]
final class OwnLineData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $values,
        public string $code,
        public string $mode,
        public bool $gift,
        public int $quantity,
        public MoneyData $unit,
        public MoneyData $total,
        public int $returnable,
        /** Its unit of sale as ordered, beside its price (shop-design.md §10.7). */
        public string $saleUnit = 'PIECE',
        /** @var array<string, array<string, string>>|null its product's card photo, by size (shop-design.md §15.2) */
        public ?array $photo = null,
        /** @var list<string> "Attribute: value", one a chip (§15.2) */
        public array $chosen = [],
    ) {}
}
