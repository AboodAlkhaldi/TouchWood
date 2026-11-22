<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A carrier serving the checkout's address (sales.md §9.5 #2): its name, logo and fee for this order; free when free shipping made it 0. */
#[TypeScript]
final class CheckoutCarrierData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $logoUrl,
        public MoneyData $fee,
        public bool $free,
    ) {}
}
