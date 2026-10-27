<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An amount for a screen: its decimal text in Latin digits, with the currency's own decimals ("12.34"),
 * and the currency's code - never a float.
 */
#[TypeScript]
final class MoneyData extends Data
{
    public function __construct(
        public string $amount,
        public string $currency,
    ) {}
}
