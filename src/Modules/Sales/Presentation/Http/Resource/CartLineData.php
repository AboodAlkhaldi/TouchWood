<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of the cart (§4.1 S4): the size's photo, name and values, its mode and pieces within their limits,
 * today's prices - the old one too while the price is lower - and its problem, null when it can be ordered.
 */
#[TypeScript]
final class CartLineData extends Data
{
    /**
     * @param  array<string, array<string, string>>|null  $photo  size => format => address
     */
    public function __construct(
        public string $variantId,
        public string $name,
        /** The product's address in this store; null when it is no longer shown. */
        public ?string $slug,
        public ?array $photo,
        public string $code,
        public string $values,
        public string $mode,
        public int $quantity,
        public ?MoneyData $unit,
        /** The price before a sale, while it is more than `unit`; else null. */
        public ?MoneyData $before,
        public ?MoneyData $total,
        public ?string $problem,
        public int $minimum,
        public ?int $maximum,
    ) {}
}
