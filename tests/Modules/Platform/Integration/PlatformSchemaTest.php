<?php

declare(strict_types=1);

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
 * @param  array<string, mixed>  $overrides
 */
function insertAuditRow(array $overrides = []): void
{
    DB::table('platform.audit_entries')->insert([
        'occurred_at' => now(),
        'actor_type' => 'SYSTEM',
        'actor_id' => null,
        'action' => 'testing.thing.changed',
        'subject_type' => 'testing.thing',
        'subject_id' => 'thing-1',
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

it('creates the platform schema and its tables', function (string $table) {
    expect(Schema::hasTable("platform.{$table}"))->toBeTrue();
})->with(['currencies', 'stores', 'audit_entries', 'settings']);

it('creates every index from the spec', function () {
    $indexes = DB::table('pg_indexes')->where('schemaname', 'platform')->pluck('indexname')->all();

    expect($indexes)->toContain(
        'platform_stores_code_unique',
        'audit_entries_subject_idx',
        'audit_entries_store_idx',
        'audit_entries_actor_idx',
        'audit_entries_action_idx',
        'settings_store_key_unique',
    );
});

it('creates every check constraint and foreign key from the spec', function () {
    $constraints = DB::table('pg_constraint')
        ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_constraint.connamespace')
        ->where('pg_namespace.nspname', 'platform')
        ->pluck('conname')
        ->all();

    expect($constraints)->toContain(
        'currencies_code_format',
        'currencies_exponent_range',
        'currencies_name_translated',
        'currencies_abbreviation_translated',
        'stores_code_format',
        'stores_country_code_format',
        'stores_tax_rate_range',
        'stores_position_range',
        'stores_name_translated',
        'platform_stores_currency_code_foreign',
        'audit_entries_actor_type',
        'audit_entries_actor_id',
        'audit_entries_ip_staff_only',
        'platform_audit_entries_store_id_foreign',
        'platform_settings_store_id_foreign',
    );
});

it('uses identity columns for the bigint keys', function (string $table) {
    $identity = DB::table('information_schema.columns')
        ->where('table_schema', 'platform')
        ->where('table_name', $table)
        ->where('column_name', 'id')
        ->value('is_identity');

    expect($identity)->toBe('YES');
})->with(['audit_entries', 'settings']);

it('stores enum columns as strings, never integers', function () {
    $enumColumns = DB::table('information_schema.columns')
        ->where('table_schema', 'platform')
        ->where(fn ($query) => $query
            ->where('column_name', 'like', '%\_type')
            ->orWhereIn('column_name', ['status', 'scope', 'visibility', 'variants_status']))
        ->get(['table_name', 'column_name', 'data_type']);

    expect($enumColumns)->not->toBeEmpty();

    foreach ($enumColumns as $column) {
        expect($column->data_type)->toBe('character varying', "{$column->table_name}.{$column->column_name} must be stored as a string");
    }
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

it('refuses rows that break the rules, even when they skip the domain', function (Closure $insert, string $constraint) {
    insertCurrencyRow();

    // The constraint name proves the intended rule refused the row, not some other error.
    expect($insert)->toThrow(QueryException::class, $constraint);
})->with([
    'store code in capitals' => [fn () => insertStoreRow(['code' => 'XA']), 'stores_code_format'],
    'tax rate above 100%' => [fn () => insertStoreRow(['tax_rate_basis_points' => 10001]), 'stores_tax_rate_range'],
    'negative tax rate' => [fn () => insertStoreRow(['tax_rate_basis_points' => -1]), 'stores_tax_rate_range'],
    'store name without English' => [fn () => insertStoreRow(['name' => json_encode(['ar' => 'متجر'])]), 'stores_name_translated'],
    'country code in lowercase' => [fn () => insertStoreRow(['country_code' => 'xa']), 'stores_country_code_format'],
    'negative position' => [fn () => insertStoreRow(['position' => -1]), 'stores_position_range'],
    'store for an unknown currency' => [fn () => insertStoreRow(['currency_code' => 'XXX']), 'platform_stores_currency_code_foreign'],
    'currency code in lowercase' => [fn () => insertCurrencyRow(['code' => 'xxx']), 'currencies_code_format'],
    'currency exponent above 6' => [fn () => insertCurrencyRow(['code' => 'XXX', 'exponent' => 7]), 'currencies_exponent_range'],
    'currency name without Arabic' => [fn () => insertCurrencyRow(['code' => 'XXX', 'name' => json_encode(['en' => 'X'])]), 'currencies_name_translated'],
    'currency abbreviation without Arabic' => [fn () => insertCurrencyRow(['code' => 'XXX', 'abbreviation' => json_encode(['en' => 'XXX'])]), 'currencies_abbreviation_translated'],
    'audit entry with an unknown actor type' => [fn () => insertAuditRow(['actor_type' => 'ROBOT', 'actor_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3']), 'audit_entries_actor_type'],
    'system audit entry with an actor id' => [fn () => insertAuditRow(['actor_type' => 'SYSTEM', 'actor_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3']), 'audit_entries_actor_id'],
    'staff audit entry without an actor id' => [fn () => insertAuditRow(['actor_type' => 'STAFF', 'actor_id' => null]), 'audit_entries_actor_id'],
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
