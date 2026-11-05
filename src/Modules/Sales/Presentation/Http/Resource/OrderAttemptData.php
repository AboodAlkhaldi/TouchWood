<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One online attempt on the order, for its Online Payments block (payments.md §4.2): each opens its own page. */
#[TypeScript]
final class OrderAttemptData extends Data
{
    public function __construct(
        public string $id,
        public string $state,
        public MoneyData $amount,
        public ?string $method,
        public bool $needsAttention,
        public string $createdAt,
    ) {}
}
