<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One row of Points › Redemptions (loyalty.md §4.1 L4). */
#[TypeScript]
final class RedemptionRowData extends Data
{
    public function __construct(
        public int $id,
        public string $orderNumber,
        public CustomerNameData $customer,
        public int $points,
        public MoneyData $discount,
        public string $occurredAt,
    ) {}
}
