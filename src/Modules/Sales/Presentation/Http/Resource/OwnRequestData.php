<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The request open on the customer's order (payments.md §4.2): what is asked, and until when. */
#[TypeScript]
final class OwnRequestData extends Data
{
    public function __construct(
        public string $requestId,
        public MoneyData $amount,
        public string $expiresAt,
    ) {}
}
