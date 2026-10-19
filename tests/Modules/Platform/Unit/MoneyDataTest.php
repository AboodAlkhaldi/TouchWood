<?php

declare(strict_types=1);

use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Domain\ValueObject\Money;

/*
| One money formatter for every screen (pricing.md §4.3.1, §9.3 S4): the store's own decimals, Latin
| digits, then the currency's sign where it has one, else its letters in the page's language
| (frontend.md §1.8, HANDOFF §5.1) - and the amount in minor units beside it.
*/

/** A store as Platform describes it, with only its currency mattering here. */
function moneyDataStore(string $currency = 'SAR', int $exponent = 2, ?string $sign = null, string $ar = 'ر.س', string $en = 'SAR'): StoreDto
{
    return new StoreDto('01k7aaaaaaaaaaaaaaaaaaaaaa', 'sa', new TranslatedTextDto('السعودية', 'Saudi Arabia'), 'SA', $currency, $exponent, $sign, new TranslatedTextDto($ar, $en), 1500, 'Asia/Riyadh', 1);
}

it('writes an amount with the store\'s two decimals and the currency\'s letters in the page\'s language', function () {
    $store = moneyDataStore();

    expect(MoneyData::of(Money::of(12_000, 'SAR'), $store, 'en'))->toEqual(new MoneyData('120.00 SAR', 12_000))
        ->and(MoneyData::of(Money::of(12_000, 'SAR'), $store, 'ar'))->toEqual(new MoneyData('120.00 ر.س', 12_000))
        ->and(MoneyData::of(Money::of(123_456_789, 'SAR'), $store, 'en')->text)->toBe('1,234,567.89 SAR')
        ->and(MoneyData::of(Money::of(5, 'SAR'), $store, 'en')->text)->toBe('0.05 SAR');
});

it('writes three decimals for a currency that has three', function () {
    $store = moneyDataStore('KWD', 3, null, 'د.ك', 'KWD');

    expect(MoneyData::of(Money::of(12_500, 'KWD'), $store, 'en'))->toEqual(new MoneyData('12.500 KWD', 12_500))
        ->and(MoneyData::of(Money::of(1_234_567, 'KWD'), $store, 'ar')->text)->toBe('1,234.567 د.ك');
});

it('writes the currency\'s sign where it has one, the same in both languages', function () {
    $store = moneyDataStore(sign: "\u{20C1}");

    expect(MoneyData::of(Money::of(14_950, 'SAR'), $store, 'en')->text)->toBe("149.50 \u{20C1}")
        ->and(MoneyData::of(Money::of(14_950, 'SAR'), $store, 'ar')->text)->toBe("149.50 \u{20C1}");
});

it('writes only Latin digits, an Arabic page included', function () {
    $text = MoneyData::of(Money::of(9_876_543, 'SAR'), moneyDataStore(), 'ar')->text;

    expect(preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $text))->toBe(0)
        ->and($text)->toBe('98,765.43 ر.س');
});

it('reads any other language as Arabic, the default language', function () {
    expect(MoneyData::of(Money::of(100, 'SAR'), moneyDataStore(), 'fr')->text)->toBe('1.00 ر.س');
});

it('refuses an amount in another currency than the store\'s', function () {
    MoneyData::of(Money::of(100, 'EGP'), moneyDataStore(), 'en');
})->throws(InvalidArgumentException::class);

it('writes a negative amount with its minus in front, in minor units too', function () {
    expect(MoneyData::of(Money::of(-1_250, 'SAR'), moneyDataStore(), 'en'))->toEqual(new MoneyData('-12.50 SAR', -1_250))
        ->and(MoneyData::of(Money::of(-123_456_789, 'SAR'), moneyDataStore(), 'ar')->text)->toBe('-1,234,567.89 ر.س')
        ->and(MoneyData::of(Money::of(-5, 'KWD'), moneyDataStore('KWD', 3, null, 'د.ك', 'KWD'), 'en')->text)->toBe('-0.005 KWD');
});
