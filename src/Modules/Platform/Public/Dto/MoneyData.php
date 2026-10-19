<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use InvalidArgumentException;
use Shared\Domain\ValueObject\Money;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **An amount as a screen shows it, already written by the server** (pricing.md §4.3.1, §9.3 S4 - one
 * money formatter for every screen of stage 5): `text` - the amount with the store's own decimals,
 * Latin digits and thousands separators (`Money::format`), then the currency's sign where it has one,
 * else its letters in the page's language ("1,234.50 ر.س", "1,234.50 SAR"; frontend.md §1.8, HANDOFF
 * §5.1) - and `minor`, the amount in the currency's smallest unit, for a page that compares or sorts.
 *
 * Platform's, because the currency is: its decimals, sign and letters are the currencies row's, carried
 * on `StoreDto`. A plain DTO, as every module boundary's is (tests/Architecture/DtoStyleTest), marked
 * for the generated types so every page reads the same shape. The page draws it with
 * `resources/js/components/Money.tsx`.
 */
#[TypeScript]
final readonly class MoneyData
{
    public function __construct(
        public string $text,
        public int $minor,
    ) {}

    /**
     * @throws InvalidArgumentException for an amount in another currency than the store's: written with
     *                                  the store's decimals and sign, it would show a wrong price
     */
    public static function of(Money $money, StoreDto $store, string $locale): self
    {
        if ($money->currencyCode !== $store->currencyCode) {
            throw new InvalidArgumentException("An amount in {$money->currencyCode} cannot be written in the currency of store {$store->code}, {$store->currencyCode}.");
        }

        return new self(
            $money->format($store->currencyExponent).' '.$store->currencySymbol($locale === 'en' ? 'en' : 'ar'),
            $money->minorUnits,
        );
    }
}
