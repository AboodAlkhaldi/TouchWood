<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** S10 - a return of the customer's (sales.md §4.1): its state, pieces, refund, the store's reason when refused; Withdraw while it waits. */
#[TypeScript]
final class OwnReturnPage extends Data
{
    /**
     * @param  list<OwnReturnLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $orderNumber,
        public string $status,
        public string $reason,
        public ?string $reasonOther,
        public ?string $staffReason,
        public string $requestedAt,
        public ?string $decidedAt,
        public ?string $receivedAt,
        public ?string $completedAt,
        public ?string $withdrawnAt,
        public ?string $closedAt,
        public ?MoneyData $refundDue,
        public array $lines,
        public int $photos,
        public string $storeTimezone,
    ) {}
}
