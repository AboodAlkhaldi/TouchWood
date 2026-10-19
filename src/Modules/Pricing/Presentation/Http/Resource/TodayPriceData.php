<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one piece pays now and why (`BASE`, `SALE`, `CATEGORY`, `QUANTITY`), the usual price struck
 * through while lower, and until when.
 */
#[TypeScript]
final class TodayPriceData extends Data
{
    public function __construct(
        public MoneyData $price,
        public ?MoneyData $before,
        public string $why,
        public bool $alwaysWins,
        public ?string $sourceId,
        public ?string $endsAt,
    ) {}
}
