<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCurrencyRow(array $overrides = []): void
{
    DB::table('platform.currencies')->insert([
        'code' => 'XTS',
        'exponent' => 2,
        'name' => json_encode(['ar' => 'عملة', 'en' => 'Currency']),
        'abbreviation' => json_encode(['ar' => 'ع', 'en' => 'XTS']),
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertStoreRow(array $overrides = []): void
{
    DB::table('platform.stores')->insert([
        'id' => strtolower((string) Str::ulid()),
        'code' => 'xa',
        'name' => json_encode(['ar' => 'متجر', 'en' => 'Store']),
        'country_code' => 'XA',
        'currency_code' => 'XTS',
        'tax_rate_basis_points' => 1500,
        'timezone' => 'UTC',
        ...$overrides,
    ]);
}

/**
 * @return array<array-key, mixed> column name => data type
 */
function platformColumnTypes(string $table): array
{
    return DB::table('information_schema.columns')
        ->where('table_schema', 'platform')
        ->where('table_name', $table)
        ->pluck('data_type', 'column_name')
        ->all();
}

it('creates the platform schema and its tables', function () {
    expect(Schema::hasTable('platform.currencies'))->toBeTrue()
        ->and(Schema::hasTable('platform.stores'))->toBeTrue();
});

it('uses the column types from the spec', function () {
    expect(platformColumnTypes('stores'))->toMatchArray([
        'id' => 'character',
        'name' => 'jsonb',
        'tax_rate_basis_points' => 'integer',
        'position' => 'smallint',
        'created_at' => 'timestamp with time zone',
    ])->and(platformColumnTypes('currencies'))->toMatchArray([
        'code' => 'character',
        'exponent' => 'smallint',
        'abbreviation' => 'jsonb',
        'sign' => 'character varying',
    ]);
});

it('refuses rows that break the rules, even when they skip the domain', function (Closure $insert) {
    insertCurrencyRow();

    expect($insert)->toThrow(QueryException::class);
})->with([
    'store code in capitals' => [fn () => insertStoreRow(['code' => 'XA'])],
    'tax rate above 100%' => [fn () => insertStoreRow(['tax_rate_basis_points' => 10001])],
    'store name without English' => [fn () => insertStoreRow(['name' => json_encode(['ar' => 'متجر'])])],
    'country code in lowercase' => [fn () => insertStoreRow(['country_code' => 'xa'])],
    'store for an unknown currency' => [fn () => insertStoreRow(['currency_code' => 'XXX'])],
    'currency exponent above 6' => [fn () => insertCurrencyRow(['code' => 'XXX', 'exponent' => 7])],
    'currency abbreviation without Arabic' => [fn () => insertCurrencyRow(['code' => 'XXX', 'abbreviation' => json_encode(['en' => 'XXX'])])],
]);

it('refuses a second store with the same code', function () {
    insertCurrencyRow();
    insertStoreRow();

    expect(fn () => insertStoreRow())->toThrow(QueryException::class);
});

it('refuses to delete a currency a store uses', function () {
    insertCurrencyRow();
    insertStoreRow();

    expect(fn () => DB::table('platform.currencies')->where('code', 'XTS')->delete())->toThrow(QueryException::class);
});
