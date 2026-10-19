<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer's points in one store, as a block of the customer page's Points section (loyalty.md
 * §4.1 L5). `mayOpen`: View Points links there - only where L2's filter offers the store.
 */
#[TypeScript]
final class StorePointsData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
        public int $points,
        public MoneyData $worth,
        public ?ExpiringData $expiring,
        public bool $mayOpen,
    ) {}
}
