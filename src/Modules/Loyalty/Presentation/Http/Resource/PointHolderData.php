<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One row of Points › Customers (loyalty.md §4.1 L1). */
#[TypeScript]
final class PointHolderData extends Data
{
    public function __construct(
        public string $accountId,
        public CustomerNameData $customer,
        public int $points,
        public MoneyData $worth,
        public ?ExpiringData $expiring,
        public ?string $lastAt,
    ) {}
}
