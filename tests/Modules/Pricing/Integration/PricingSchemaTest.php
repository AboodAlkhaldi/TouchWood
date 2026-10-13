<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Modules\Pricing\Infrastructure\Persistence\PricingSchema;
use Modules\Pricing\Public\Enums\PriceKind;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The pricing schema (pricing.md §5). The tables and their checks join this file with the steps that
| create them: every CHECK broken once, a nullable column left NULL where it has one (§8 #15).
*/

uses(RefreshDatabase::class);

it('creates the pricing schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'pricing')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('pricing');
});

// A test database keeps its schemas from one run to the next (migrate:fresh drops tables, never
// schemas), so the test above cannot tell whether the migration still creates it. This one starts
// from nothing and runs what the migration runs; PostgreSQL's DDL is transactional, so the test's own
// transaction puts everything back (as Catalog's schema test).
it('creates the schema from nothing, and its rollback drops it', function () {
    DB::statement('DROP SCHEMA pricing CASCADE');

    PricingSchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'pricing')->exists())->toBeTrue();

    PricingSchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'pricing')->exists())->toBeFalse();
});

it('stores its kinds as strings, never integers (§5)', function () {
    $kinds = DB::table('information_schema.columns')
        ->where('table_schema', 'pricing')
        ->whereIn('column_name', ['mode', 'kind', 'source'])
        ->orderBy('table_name')->orderBy('column_name')
        ->get(['table_name', 'column_name', 'data_type']);

    expect($kinds->map(static fn (stdClass $column): string => "{$column->table_name}.{$column->column_name} {$column->data_type}")->all())->toBe([
        'price_candidates.kind character varying',
        'price_candidates.mode character varying',
        'retail_prices.source character varying',
    ]);
});

it('allows in each kind\'s column exactly the values the code has', function (string $constraint, array $values) {
    // A new case the CHECK does not know would pass every unit test and fail on insert.
    $definition = DB::selectOne('select pg_get_constraintdef(oid) as definition from pg_constraint where conname = ?', [$constraint])?->definition;
    preg_match('/ARRAY\[([^\]]*)\]/', (string) $definition, $list);
    preg_match_all("/'([A-Z_]+)'::/", $list[1] ?? '', $allowed);

    expect($allowed[1])->toEqualCanonicalizing($values);
})->with([
    'modes' => ['price_candidates_mode', array_map(static fn (SaleMode $mode): string => $mode->value, SaleMode::cases())],
    'kinds' => ['price_candidates_kind', array_map(static fn (PriceKind $kind): string => $kind->value, PriceKind::cases())],
    'sources' => ['retail_prices_source', array_map(static fn (PriceSource $source): string => $source->value, PriceSource::cases())],
]);

describe('what the database refuses behind the code (§8 #15)', function () {
    beforeEach(function () {
        seed(PlatformSeeder::class);
        seed(CatalogSeeder::class);
    });

    it('keeps a retail price above 0, in a currency, from a known source', function () {
        $row = ['store_id' => Fx::storeId('sa'), 'variant_id' => Px::ready()['variants'][0], 'amount_minor' => 100, 'currency_code' => 'SAR', 'source' => 'STAFF', 'updated_at' => now()];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('pricing.retail_prices')->insert([...$row, ...$changed]));

        expect($insert(['amount_minor' => 0]))->toThrow(QueryException::class, 'retail_prices_positive')
            ->and($insert(['currency_code' => 'sar']))->toThrow(QueryException::class, 'retail_prices_currency')
            ->and($insert(['source' => 'GUESS']))->toThrow(QueryException::class, 'retail_prices_source');

        $insert([])();

        expect($insert(['amount_minor' => 200]))->toThrow(QueryException::class, 'retail_prices_pkey');
    });

    it('keeps a candidate above 0, on a line, of a kind, from 1 piece, with a window only where one belongs', function () {
        $row = [
            'store_id' => Fx::storeId('sa'), 'variant_id' => Px::ready()['variants'][0], 'mode' => 'RETAIL', 'from_quantity' => 1,
            'amount_minor' => 100, 'currency_code' => 'SAR', 'kind' => 'SALE', 'always_wins' => false,
            'source_id' => strtolower((string) Str::ulid()), 'starts_at' => null, 'ends_at' => null,
        ];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('pricing.price_candidates')->insert([...$row, ...$changed]));

        expect($insert(['amount_minor' => 0]))->toThrow(QueryException::class, 'price_candidates_positive')
            ->and($insert(['currency_code' => 'SA']))->toThrow(QueryException::class, 'price_candidates_currency')
            ->and($insert(['mode' => 'BOTH']))->toThrow(QueryException::class, 'price_candidates_mode')
            ->and($insert(['kind' => 'GIFT']))->toThrow(QueryException::class, 'price_candidates_kind')
            ->and($insert(['from_quantity' => 0]))->toThrow(QueryException::class, 'price_candidates_from_quantity')
            ->and($insert(['starts_at' => '2026-10-11 10:00:00+00', 'ends_at' => '2026-10-11 10:00:00+00']))->toThrow(QueryException::class, 'price_candidates_window')
            // The retail price and a band have no window and never always win; each NULL-able column broken alone.
            ->and($insert(['kind' => 'BASE', 'starts_at' => '2026-10-11 10:00:00+00']))->toThrow(QueryException::class, 'price_candidates_undated')
            ->and($insert(['kind' => 'QUANTITY', 'ends_at' => '2026-10-11 10:00:00+00']))->toThrow(QueryException::class, 'price_candidates_undated')
            ->and($insert(['kind' => 'BASE', 'always_wins' => true]))->toThrow(QueryException::class, 'price_candidates_undated')
            // A sale's or a discount's candidate names it.
            ->and($insert(['source_id' => null]))->toThrow(QueryException::class, 'price_candidates_source')
            ->and($insert(['kind' => 'CATEGORY', 'source_id' => null]))->toThrow(QueryException::class, 'price_candidates_source');

        // A sale may start with no end, end with no start known here, or have both; the retail price none.
        $insert(['starts_at' => '2026-10-11 10:00:00+00'])();
        $insert(['ends_at' => '2026-10-11 10:00:00+00'])();
        $insert(['starts_at' => '2026-10-11 10:00:00+00', 'ends_at' => '2026-10-12 10:00:00+00', 'always_wins' => true])();
        $insert(['kind' => 'BASE', 'source_id' => null])();
        $insert(['kind' => 'BASE', 'source_id' => null, 'mode' => 'WHOLESALE'])();

        // One retail price a size and a line in a store.
        expect($insert(['kind' => 'BASE', 'source_id' => null, 'amount_minor' => 90]))->toThrow(QueryException::class, 'price_candidates_one_base')
            ->and(DB::table('pricing.price_candidates')->count())->toBe(5);
    });

    it('never lets a store with prices go, and takes a size\'s prices with it when it goes', function () {
        // A store is held by many modules' rows, so which refusal comes first is not Pricing's to
        // say: the rule each key was made with is read from the database itself.
        $rules = collect(DB::select(
            "SELECT conname, confdeltype FROM pg_constraint WHERE contype = 'f' AND connamespace = 'pricing'::regnamespace ORDER BY conname",
        ))->mapWithKeys(static fn (stdClass $key): array => [(string) $key->conname => (string) $key->confdeltype])->all();

        expect($rules)->toBe([
            'price_candidates_store' => 'r',
            'price_candidates_variant' => 'c',
            'retail_prices_store' => 'r',
            'retail_prices_variant' => 'c',
            'sale_variants_sale' => 'c',
            'sale_variants_variant' => 'c',
            'sales_product' => 'c',
            'sales_store' => 'r',
            'wholesale_bands_store' => 'r',
            'wholesale_bands_variant' => 'c',
        ]);

        $size = Px::ready()['variants'][0];
        $store = Fx::storeId('ae');
        DB::table('pricing.retail_prices')->insert(['store_id' => $store, 'variant_id' => $size, 'amount_minor' => 100, 'currency_code' => 'AED', 'source' => 'STAFF', 'updated_at' => now()]);
        DB::table('pricing.price_candidates')->insert(['store_id' => $store, 'variant_id' => $size, 'mode' => 'RETAIL', 'from_quantity' => 1, 'amount_minor' => 100, 'currency_code' => 'AED', 'kind' => 'BASE', 'always_wins' => false]);

        DB::table('catalog.variants')->where('id', $size)->delete();

        expect(DB::table('pricing.retail_prices')->count())->toBe(0)
            ->and(DB::table('pricing.price_candidates')->count())->toBe(0);
    });
});
