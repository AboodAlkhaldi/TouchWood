<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * P4 - editing an order (sales.md §4.1), its own page: the lines with their pieces and Remove, Add Product, another
 * of the customer's addresses in the store, the gift, a reason; Preview, then Confirm once the preview is of what
 * is on screen (R2-36).
 */
#[TypeScript]
final class EditPage extends Data
{
    /**
     * @param  list<EditLineData>  $lines
     * @param  list<CheckoutAddressData>  $addresses
     */
    public function __construct(
        public string $id,
        public string $number,
        public string $audience,
        public array $lines,
        public ?string $gift,
        public array $addresses,
        public string $address,
        public MoneyData $total,
        public MoneyData $paid,
        public string $storeTimezone,
    ) {}
}
