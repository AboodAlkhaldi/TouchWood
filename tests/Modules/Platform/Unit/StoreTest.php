<?php

use Modules\Platform\Domain\Exception\InvalidStoreAttribute;
use Modules\Platform\Domain\Exception\InvalidTaxRate;
use Modules\Platform\Domain\Exception\InvalidTimezone;
use Modules\Platform\Domain\Exception\MissingTranslation;
use Modules\Platform\Domain\Model\Store;
use Modules\Platform\Domain\ValueObject\CountryCode;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Domain\ValueObject\TaxRate;
use Modules\Platform\Domain\ValueObject\Timezone;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Shared\Domain\ValueObject\StoreId;

function storeForTest(): Store
{
    return Store::create(
        StoreId::fromString('01J8Z3K4M5N6P7Q8R9S0T1V2W3'),
        StoreCode::fromString('xa'),
        TranslatedText::of('متجر تجريبي', 'Test store', 'name'),
        CountryCode::fromString('XA'),
        CurrencyCode::fromString('XTS'),
        TaxRate::fromBasisPoints(1500),
        Timezone::fromString('UTC'),
        1,
    );
}

describe('creating', function () {
    it('holds every attribute it was created with', function () {
        $store = storeForTest();

        expect($store->code()->value)->toBe('xa')
            ->and($store->name()->ar)->toBe('متجر تجريبي')
            ->and($store->name()->en)->toBe('Test store')
            ->and($store->country()->value)->toBe('XA')
            ->and($store->currency()->value)->toBe('XTS')
            ->and($store->taxRate()->basisPoints)->toBe(1500)
            ->and($store->timezone()->identifier)->toBe('UTC')
            ->and($store->position())->toBe(1)
            ->and($store->pullChanges())->toBe([]);
    });

    it('accepts a code of 2 to 8 lowercase letters', function (string $code) {
        expect(StoreCode::fromString($code)->value)->toBe($code);
    })->with(['xa', 'abcdefgh']);

    it('rejects a malformed code', function (string $code) {
        StoreCode::fromString($code);
    })->throws(InvalidStoreAttribute::class)->with(['', 'x', 'XA', 'abcdefghi', 'x1', 'x-a']);

    it('rejects a malformed country code', function (string $code) {
        CountryCode::fromString($code);
    })->throws(InvalidStoreAttribute::class)->with(['', 'xa', 'XAB', 'X1']);

    it('accepts tax rates from 0% to 100%', function (int $basisPoints) {
        expect(TaxRate::fromBasisPoints($basisPoints)->basisPoints)->toBe($basisPoints);
    })->with([0, 500, 1500, 10000]);

    it('rejects a tax rate outside 0% to 100%', function (int $basisPoints) {
        TaxRate::fromBasisPoints($basisPoints);
    })->throws(InvalidTaxRate::class)->with([-1, 10001]);

    it('rejects a timezone that is not an IANA identifier', function (string $timezone) {
        Timezone::fromString($timezone);
    })->throws(InvalidTimezone::class)->with(['', 'Mars/Olympus', 'GMT+3', 'asia/riyadh']);

    it('requires both an Arabic and an English name', function (string $ar, string $en) {
        TranslatedText::of($ar, $en, 'name');
    })->throws(MissingTranslation::class)->with([
        'no Arabic' => ['', 'Store'],
        'blank English' => ['متجر', '   '],
    ]);

    it('rejects a position outside the display range', function (int $position) {
        storeForTest()->reposition($position);
    })->throws(InvalidStoreAttribute::class)->with([-1, 32768]);
});

describe('changing', function () {
    it('records which attributes changed', function () {
        $store = storeForTest();

        $store->rename(TranslatedText::of('اسم جديد', 'New name', 'name'));
        $store->changeTaxRate(TaxRate::fromBasisPoints(1600));
        $store->changeTimezone(Timezone::fromString('Asia/Riyadh'));
        $store->reposition(4);

        expect($store->pullChanges())->toBe(['name', 'tax_rate_basis_points', 'timezone', 'position'])
            ->and($store->taxRate()->basisPoints)->toBe(1600);
    });

    it('does not record a change that sets the same value', function () {
        $store = storeForTest();

        $store->rename(TranslatedText::of('متجر تجريبي', 'Test store', 'name'));
        $store->changeTaxRate(TaxRate::fromBasisPoints(1500));
        $store->changeTimezone(Timezone::fromString('UTC'));
        $store->reposition(1);

        expect($store->pullChanges())->toBe([]);
    });

    it('clears the recorded changes once they are read', function () {
        $store = storeForTest();
        $store->reposition(2);
        $store->pullChanges();

        expect($store->pullChanges())->toBe([]);
    });

    it('offers no way to change the code, country or currency', function () {
        $methods = array_map(fn (ReflectionMethod $method): string => strtolower($method->getName()), (new ReflectionClass(Store::class))->getMethods(ReflectionMethod::IS_PUBLIC));

        expect(array_filter($methods, fn (string $name): bool => preg_match('/^(change|set|update)(code|country|currency)/', $name) === 1))->toBe([]);
    });
});
