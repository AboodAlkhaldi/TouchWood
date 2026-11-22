<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Shared\Domain\ValueObject\Money;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An amount for a screen (promotions.md §4.1): its decimal text in Latin digits with the currency's own
 * decimals ("12.34"), and the currency's code - never a float. `lib/money.ts` writes it.
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
        return new self(self::decimal($money->minorUnits, $exponent), $money->currencyCode);
    }

    /** "1234.50" from 123450 at two decimals: the text a box shows and sends back. */
    public static function decimal(int $minor, int $exponent): string
    {
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.($exponent === 0 ? $digits : substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent));
    }
}
