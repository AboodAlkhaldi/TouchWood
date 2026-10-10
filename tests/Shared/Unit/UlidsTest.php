<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Shared\Infrastructure\Persistence\Ulids;

/*
| Whether an id from a caller is a ULID, before it reaches a query (the Shared kernel's, owner
| 2026-10-10): anything else is answered "not found" without asking the database.
*/

it('takes a ULID as Str::ulid() makes it, in either case', function () {
    $ulid = (string) Str::ulid();

    expect(Ulids::valid(strtolower($ulid)))->toBeTrue()
        ->and(Ulids::valid(strtoupper($ulid)))->toBeTrue()
        ->and(Ulids::valid('01j8z3k4m5n6p7q8r9s0t1v2w3'))->toBeTrue();
});

it('refuses anything that is not one', function (string $id) {
    expect(Ulids::valid($id))->toBeFalse();
})->with([
    'empty' => [''],
    'one character short' => ['01j8z3k4m5n6p7q8r9s0t1v2w'],
    'one character long' => ['01j8z3k4m5n6p7q8r9s0t1v2w34'],
    'a first character past 7' => ['81j8z3k4m5n6p7q8r9s0t1v2w3'],
    // Crockford base32 has no I, L, O or U.
    'an I' => ['01j8z3k4m5n6p7q8r9s0t1v2wi'],
    'an L' => ['01j8z3k4m5n6p7q8r9s0t1v2wl'],
    'an O' => ['01j8z3k4m5n6p7q8r9s0t1v2wo'],
    'a U' => ['01j8z3k4m5n6p7q8r9s0t1v2wu'],
    'a trailing newline' => ["01j8z3k4m5n6p7q8r9s0t1v2w3\n"],
    'a UUID' => ['0190c2a4-7a1b-7cde-8f00-112233445566'],
    'bytes that are not UTF-8' => ["\xC3\x28"],
]);
