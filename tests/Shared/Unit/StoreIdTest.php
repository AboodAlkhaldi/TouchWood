<?php

use Shared\Domain\ValueObject\StoreId;

it('accepts a ULID and keeps it in lowercase', function () {
    expect(StoreId::fromString('01J8Z3K4M5N6P7Q8R9S0T1V2W3')->value)->toBe('01j8z3k4m5n6p7q8r9s0t1v2w3');
});

it('rejects anything that is not a ULID', function (string $value) {
    StoreId::fromString($value);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'store code' => ['sa'],
    'uuid' => ['4f1c2e2a-6d3b-4c1e-9a7b-2f6e8d1c0b9a'],
    'forbidden letter U' => ['01J8Z3K4M5N6P7Q8R9S0T1V2WU'],
    'too short' => ['01J8Z3K4M5N6P7Q8R9S0T1V2W'],
    'too long' => ['01J8Z3K4M5N6P7Q8R9S0T1V2W34'],
    'beyond the largest timestamp' => ['81J8Z3K4M5N6P7Q8R9S0T1V2W3'],
]);

it('compares by value regardless of the case it was given in', function () {
    expect(StoreId::fromString('01J8Z3K4M5N6P7Q8R9S0T1V2W3')->equals(StoreId::fromString('01j8z3k4m5n6p7q8r9s0t1v2w3')))->toBeTrue()
        ->and(StoreId::fromString('01J8Z3K4M5N6P7Q8R9S0T1V2W3')->equals(StoreId::fromString('01J8Z3K4M5N6P7Q8R9S0T1V2W4')))->toBeFalse();
});
