<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Shared\Domain\ValueObject\Money;
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

    public static function of(Money $money, int $exponent): self
    {
        $minor = $money->minorUnits;
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);
        $amount = $exponent === 0 ? $digits : substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);

        return new self($sign.$amount, $money->currencyCode);
    }
}
