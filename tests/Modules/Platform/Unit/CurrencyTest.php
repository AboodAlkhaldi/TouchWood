<?php

use Modules\Platform\Domain\Exception\CurrencyExponentLocked;
use Modules\Platform\Domain\Exception\InvalidCurrencyAttribute;
use Modules\Platform\Domain\Model\Currency;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;

function currencyForTest(?string $sign = "\u{20C1}", int $exponent = 2): Currency
{
    return Currency::create(
        CurrencyCode::fromString('XTS'),
        $exponent,
        TranslatedText::of('عملة', 'Currency', 'name'),
        TranslatedText::of('ع.ت', 'XTS', 'abbreviation'),
        $sign,
    );
}

describe('creating', function () {
    it('rejects a malformed currency code', function (string $code) {
        CurrencyCode::fromString($code);
    })->throws(InvalidCurrencyAttribute::class)->with(['', 'xts', 'XT', 'XTSS', 'X1S']);

    it('accepts an exponent from 0 to 6', function (int $exponent) {
        expect(currencyForTest(exponent: $exponent)->exponent())->toBe($exponent);
    })->with([0, 2, 3, 6]);

    it('rejects an exponent outside 0 to 6', function (int $exponent) {
        currencyForTest(exponent: $exponent);
    })->throws(InvalidCurrencyAttribute::class)->with([-1, 7]);

    it('accepts a single-character sign or no sign', function (?string $sign) {
        expect(currencyForTest($sign)->sign())->toBe($sign);
    })->with([
        'Saudi Riyal sign' => ["\u{20C1}"],
        'UAE Dirham sign' => ["\u{20C3}"],
        'no sign' => [null],
    ]);

    it('rejects a sign that is not exactly one character', function (string $sign) {
        currencyForTest($sign);
    })->throws(InvalidCurrencyAttribute::class)->with(['', 'SR', "\u{20C1}\u{20C3}"]);
});

describe('changing', function () {
    it('changes the exponent while no store uses the currency', function () {
        $currency = currencyForTest();
        $currency->changeExponent(3, inUse: false);

        expect($currency->exponent())->toBe(3)
            ->and($currency->pullChanges())->toBe(['exponent']);
    });

    it('locks the exponent once a store uses the currency', function () {
        currencyForTest()->changeExponent(3, inUse: true);
    })->throws(CurrencyExponentLocked::class);

    it('allows setting the same exponent even when locked', function () {
        $currency = currencyForTest();
        $currency->changeExponent(2, inUse: true);

        expect($currency->pullChanges())->toBe([]);
    });

    it('clears the sign so prices fall back to letters', function () {
        $currency = currencyForTest();
        $currency->changeSign(null);

        expect($currency->sign())->toBeNull()
            ->and($currency->pullChanges())->toBe(['sign']);
    });

    it('records name and abbreviation changes only when they differ', function () {
        $currency = currencyForTest();

        $currency->rename(TranslatedText::of('عملة', 'Currency', 'name'));
        $currency->changeAbbreviation(TranslatedText::of('ع', 'XT', 'abbreviation'));

        expect($currency->pullChanges())->toBe(['abbreviation']);
    });
});

describe('displaying a currency', function () {
    it('shows the sign in both languages when there is one', function () {
        $currency = new CurrencyDto('XTS', 2, new TranslatedTextDto('عملة', 'Currency'), new TranslatedTextDto('ع.ت', 'XTS'), "\u{20C1}");

        expect($currency->displaySymbol('ar'))->toBe("\u{20C1}")
            ->and($currency->displaySymbol('en'))->toBe("\u{20C1}");
    });

    it('shows the letters in the page language when there is no sign', function () {
        $currency = new CurrencyDto('XTS', 2, new TranslatedTextDto('عملة', 'Currency'), new TranslatedTextDto('ع.ت', 'XTS'), null);

        expect($currency->displaySymbol('ar'))->toBe('ع.ت')
            ->and($currency->displaySymbol('en'))->toBe('XTS');
    });

    it('gives a store its currency symbol the same way', function () {
        $store = new StoreDto('01j8z3k4m5n6p7q8r9s0t1v2w3', 'xa', new TranslatedTextDto('متجر', 'Store'), 'XA', 'XTS', 2, null, new TranslatedTextDto('ع.ت', 'XTS'), 1500, 'UTC', 1);

        expect($store->currencySymbol('ar'))->toBe('ع.ت')
            ->and($store->currencySymbol('en'))->toBe('XTS');
    });
});
