<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An order in My Orders (§4.1 S7): its number, when, the status a customer sees, total and pieces. */
#[TypeScript]
final class OwnOrderRowData extends Data
{
    public function __construct(
        public string $number,
        public string $placedAt,
        public string $status,
        public MoneyData $total,
        public int $pieces,
    ) {}
}
