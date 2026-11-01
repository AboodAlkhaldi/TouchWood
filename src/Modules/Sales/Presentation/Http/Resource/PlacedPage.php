<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** S6 - the order placed (sales.md §4.1): its number, total and way to pay; a bank transfer's details and its receipts. */
#[TypeScript]
final class PlacedPage extends Data
{
    public function __construct(
        public string $number,
        public MoneyData $total,
        public string $wayToPay,
        public ?BankData $bank,
        public int $receipts,
        public bool $mayUpload,
        /** No carrier served the place: the total is before shipping (sales.md §4.3). */
        public bool $shippingPending = false,
    ) {}
}
