<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The request open on the order: "Requested 45.00 SAR, open until 15:20" (payments.md §4.2). */
#[TypeScript]
final class OrderRequestData extends Data
{
    public function __construct(
        public MoneyData $amount,
        public string $expiresAt,
    ) {}
}
