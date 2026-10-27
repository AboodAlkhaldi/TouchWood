<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A return of the order (§1.8): its state, when asked, its refund once completed, the store's reason when refused. */
#[TypeScript]
final class OrderReturnData extends Data
{
    public function __construct(
        public string $id,
        public string $status,
        public string $requestedAt,
        public ?MoneyData $refundDue,
        /** The words staff wrote when they refused it, as written; null unless refused (§4.2 B16). */
        public ?string $staffReason = null,
    ) {}
}
