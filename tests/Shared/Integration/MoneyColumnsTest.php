<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Money is kept as whole minor units in a bigint, beside its currency (handoff §19; STRUCTURE.md, "Every
| money column is bigint"): never a decimal, which rounds where nobody looks, and never a float. Read
| from the database itself, over every schema the application owns, so a module's new table is checked
| the day it is migrated.
*/

uses(RefreshDatabase::class);

/**
 * Every column in the application's own schemas, with its type.
 *
 * @return list<stdClass>
 */
function moneyColumnsAll(): array
{
    return array_values(DB::table('information_schema.columns')
        ->whereNotIn('table_schema', ['pg_catalog', 'information_schema', 'pg_toast'])
        ->orderBy('table_schema')->orderBy('table_name')->orderBy('column_name')
        ->get(['table_schema', 'table_name', 'column_name', 'data_type'])
        ->all());
}

it('keeps every amount in minor units as a bigint', function () {
    $money = array_values(array_filter(moneyColumnsAll(), static fn (stdClass $column): bool => str_ends_with((string) $column->column_name, '_minor')));

    // A guard over columns must find some, or it passes over nothing: Pricing's and Catalog's at least.
    expect(count($money))->toBeGreaterThanOrEqual(5);

    foreach ($money as $column) {
        expect($column->data_type)->toBe('bigint', "{$column->table_schema}.{$column->table_name}.{$column->column_name} must be a bigint");
    }
});

it('has no decimal, float or money column but the three that are not money', function () {
    $inexact = array_map(
        static fn (stdClass $column): string => "{$column->table_schema}.{$column->table_name}.{$column->column_name} ({$column->data_type})",
        array_values(array_filter(moneyColumnsAll(), static fn (stdClass $column): bool => in_array($column->data_type, ['numeric', 'real', 'double precision', 'money'], true))),
    );

    // A map pin and a product detail's number (a thickness, say) are measures, not money. A new
    // decimal column is named here on purpose, or it is a DECIMAL sneaking in.
    expect($inexact)->toBe([
        'access.addresses.latitude (numeric)',
        'access.addresses.longitude (numeric)',
        'catalog.variant_details.number (numeric)',
    ]);
});
