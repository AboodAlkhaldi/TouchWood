<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Application\Command\OrderAttributes\OrderAttributes;
use Modules\Catalog\Application\Command\OrderAttributes\OrderAttributesHandler;
use Modules\Catalog\Application\Command\OrderAttributeValues\OrderAttributeValues;
use Modules\Catalog\Application\Command\OrderAttributeValues\OrderAttributeValuesHandler;
use Modules\Catalog\Application\Command\OrderBrands\OrderBrands;
use Modules\Catalog\Application\Command\OrderBrands\OrderBrandsHandler;
use Modules\Catalog\Application\Command\OrderLabels\OrderLabels;
use Modules\Catalog\Application\Command\OrderLabels\OrderLabelsHandler;
use Modules\Catalog\Application\Command\OrderWarranties\OrderWarranties;
use Modules\Catalog\Application\Command\OrderWarranties\OrderWarrantiesHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Warranty;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's ordered lists, ordered by dragging (catalog.md amendment 16(d), P29): the brands, the
| labels, the warranties, the attributes and each attribute's values. An order is the ids in their
| new order: those sent first, every other after them in the order it had, every position written
| again 1, 2, 3 … — each under its list's own job with All stores, each item whose place changed
| audited. A new item goes last; the warranties gained their order with this amendment, the ones
| already there numbered in name order.
|
| What every list change shares - its lock first, an id not in its list, an order that moves nothing
| - is checked with the others in CatalogListGuardsTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * A list's ids in its order, as the screens read it.
 *
 * @param  array<string, string>  $scope  an attribute's values: its id
 * @return list<string>
 */
function catalogOrdersOf(string $table, array $scope = []): array
{
    return array_values(array_map('strval', DB::table($table)->where($scope)->orderBy('position')->orderBy('name_en')->orderBy('id')->pluck('id')->all()));
}

/**
 * @param  array<string, string>  $scope
 * @return list<int>
 */
function catalogOrdersPositions(string $table, array $scope = []): array
{
    return array_values(array_map('intval', DB::table($table)->where($scope)->orderBy('position')->pluck('position')->all()));
}

function catalogOrdersLabel(string $nameEn): string
{
    return Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('شارة', $nameEn, 'blue')));
}

function catalogOrdersWarranty(string $nameEn): string
{
    $terms = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Terms']]]]];

    return Fx::asSystem(fn (): string => app(AddWarrantyHandler::class)->handle(new AddWarranty('ضمان', $nameEn, $terms, $terms, 12)));
}

/**
 * @param  array<string, string>  $scope
 * @param  list<string>  $ids
 */
function catalogOrdersSendBrands(array $scope, array $ids): void
{
    app(OrderBrandsHandler::class)->handle(new OrderBrands($ids));
}

/**
 * @param  array<string, string>  $scope
 * @param  list<string>  $ids
 */
function catalogOrdersSendLabels(array $scope, array $ids): void
{
    app(OrderLabelsHandler::class)->handle(new OrderLabels($ids));
}

/**
 * @param  array<string, string>  $scope
 * @param  list<string>  $ids
 */
function catalogOrdersSendWarranties(array $scope, array $ids): void
{
    app(OrderWarrantiesHandler::class)->handle(new OrderWarranties($ids));
}

/**
 * @param  array<string, string>  $scope
 * @param  list<string>  $ids
 */
function catalogOrdersSendAttributes(array $scope, array $ids): void
{
    app(OrderAttributesHandler::class)->handle(new OrderAttributes($ids));
}

/**
 * @param  array<string, string>  $scope  the attribute's id
 * @param  list<string>  $ids
 */
function catalogOrdersSendValues(array $scope, array $ids): void
{
    app(OrderAttributeValuesHandler::class)->handle(new OrderAttributeValues($scope['attribute_id'], $ids));
}

/**
 * Every ordered list: its job, its table, its audit subject, a way to add one more item (with the
 * list's scope), and the order command.
 *
 * @return array<string, array{string, string, string, Closure, Closure}>
 */
function catalogOrdersLists(): array
{
    return [
        'brands' => [
            CatalogPermissions::BRAND_MANAGE, 'catalog.brands', 'brand',
            fn (): array => [fn (): string => Px::brand('Brand'), []],
            catalogOrdersSendBrands(...),
        ],
        'labels' => [
            CatalogPermissions::LABEL_MANAGE, 'catalog.labels', 'label',
            fn (): array => [fn (): string => catalogOrdersLabel('Label'), []],
            catalogOrdersSendLabels(...),
        ],
        'warranties' => [
            CatalogPermissions::WARRANTY_MANAGE, 'catalog.warranties', 'warranty',
            fn (): array => [fn (): string => catalogOrdersWarranty('Warranty'), []],
            catalogOrdersSendWarranties(...),
        ],
        'attributes' => [
            CatalogPermissions::ATTRIBUTE_MANAGE, 'catalog.attributes', 'attribute',
            fn (): array => [fn (): string => Px::attribute('Attribute'), []],
            catalogOrdersSendAttributes(...),
        ],
        'an attribute\'s values' => [
            CatalogPermissions::ATTRIBUTE_MANAGE, 'catalog.attribute_values', 'attribute_value',
            function (): array {
                $width = Px::attribute('Width');
                $n = 0;

                return [function () use ($width, &$n): string {
                    $n++;

                    return Px::value($width, "{$n}0 cm");
                }, ['attribute_id' => $width]];
            },
            catalogOrdersSendValues(...),
        ],
    ];
}

describe('an order', function () {
    it('puts the ids sent first, in the order sent, every other after them in its order, written 1, 2, 3 …', function (string $job, string $table, string $subject, Closure $make, Closure $order) {
        [$add, $scope] = $make();
        [$a, $b, $c, $d] = [$add(), $add(), $add(), $add()];
        Cx::actAsStaffWith([$job]);

        expect(catalogOrdersOf($table, $scope))->toBe([$a, $b, $c, $d]);

        $order($scope, [$d, strtoupper($b)]);

        expect(catalogOrdersOf($table, $scope))->toBe([$d, $b, $a, $c])
            ->and(catalogOrdersPositions($table, $scope))->toBe([1, 2, 3, 4]);
    })->with(catalogOrdersLists());

    it('audits each item whose place changed, from what it was to what it is, and none that stayed', function (string $job, string $table, string $subject, Closure $make, Closure $order) {
        [$add, $scope] = $make();
        [$a, $b, $c] = [$add(), $add(), $add()];
        Cx::actAsStaffWith([$job]);

        // Move to Top: the one id sent.
        $order($scope, [$b]);
        $changes = static fn (string $id): array => (array) json_decode((string) DB::table('platform.audit_entries')->where('action', "catalog.{$subject}.ordered")->where('subject_id', $id)->value('changes'), true);

        expect(catalogOrdersOf($table, $scope))->toBe([$b, $a, $c])
            ->and(Fx::audits("catalog.{$subject}.ordered"))->toBe(2)
            ->and($changes($b))->toBe(['position' => [2, 1]])
            ->and($changes($a))->toBe(['position' => [1, 2]])
            ->and(Fx::audits("catalog.{$subject}.ordered", $c))->toBe(0)
            // The list belongs to no store: its entries name none.
            ->and(DB::table('platform.audit_entries')->where('action', "catalog.{$subject}.ordered")->whereNotNull('store_id')->count())->toBe(0);
    })->with(catalogOrdersLists());

    it('is refused to someone holding the list\'s job in one store only, or another job, and changes nothing', function (string $job, string $table, string $subject, Closure $make, Closure $order) {
        [$add, $scope] = $make();
        [$a, $b] = [$add(), $add()];

        Cx::actAsStaffWith([$job], ['sa']);

        expect(fn () => $order($scope, [$b]))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);

        expect(fn () => $order($scope, [$b]))->toThrow(Unauthorized::class)
            ->and(catalogOrdersOf($table, $scope))->toBe([$a, $b])
            ->and(Fx::audits("catalog.{$subject}.ordered"))->toBe(0);
    })->with(catalogOrdersLists());

    it('refuses an id sent twice, before anything is read', function () {
        $first = Px::brand('Blum');
        $second = Px::brand('Hettich');
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
        $queries = Cx::recordQueries();

        expect(fn () => app(OrderBrandsHandler::class)->handle(new OrderBrands([$second, $first, strtoupper($second)])))->toThrow(InvalidCatalogAttribute::class, 'ids')
            ->and(array_values(array_filter((array) $queries, static fn (array $query): bool => str_contains($query['sql'], '"catalog".') || str_contains($query['sql'], 'catalog.'))))->toBe([])
            ->and(catalogOrdersOf('catalog.brands'))->toBe([$first, $second]);
    });

    it('orders one attribute\'s values and no other\'s', function () {
        $width = Px::attribute('Width');
        $height = Px::attribute('Height');
        [$narrow, $wide] = [Px::value($width, '45 cm'), Px::value($width, '80 cm')];
        [$low, $high] = [Px::value($height, '70 cm'), Px::value($height, '90 cm')];
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);

        app(OrderAttributeValuesHandler::class)->handle(new OrderAttributeValues($width, [$wide]));

        expect(catalogOrdersOf('catalog.attribute_values', ['attribute_id' => $width]))->toBe([$wide, $narrow])
            ->and(catalogOrdersOf('catalog.attribute_values', ['attribute_id' => $height]))->toBe([$low, $high])
            ->and(catalogOrdersPositions('catalog.attribute_values', ['attribute_id' => $height]))->toBe([1, 2]);
    });
});

describe('a new item', function () {
    it('goes last, after an order too, with no place typed', function (string $job, string $table, string $subject, Closure $make, Closure $order) {
        [$add, $scope] = $make();
        [$a, $b] = [$add(), $add()];
        Cx::actAsStaffWith([$job]);
        $order($scope, [$b]);

        $new = $add();

        expect(catalogOrdersOf($table, $scope))->toBe([$b, $a, $new])
            ->and(catalogOrdersPositions($table, $scope))->toBe([1, 2, 3])
            // Its place is in what the audit log keeps of it.
            ->and((array) json_decode((string) DB::table('platform.audit_entries')->where('action', "catalog.{$subject}.added")->where('subject_id', $new)->value('changes'), true))->toMatchArray(['position' => [null, 3]]);
    })->with(catalogOrdersLists());

    it('numbers the list again 1, 2, 3 in its order when the next place would pass the cap', function () {
        $first = catalogOrdersLabel('First');
        $second = catalogOrdersLabel('Second');
        // A list never ordered since its places were typed, the last at the cap.
        DB::table('catalog.labels')->where('id', $first)->update(['position' => 9000]);
        DB::table('catalog.labels')->where('id', $second)->update(['position' => 10000]);
        $width = Px::attribute('Width');
        $value = Px::value($width, '45 cm');
        DB::table('catalog.attribute_values')->where('id', $value)->update(['position' => 10000]);
        $other = Px::value(Px::attribute('Height'), '70 cm');

        $label = catalogOrdersLabel('Third');
        $next = Px::value($width, '60 cm');

        expect(catalogOrdersOf('catalog.labels'))->toBe([$first, $second, $label])
            ->and(catalogOrdersPositions('catalog.labels'))->toBe([1, 2, 3])
            ->and(catalogOrdersOf('catalog.attribute_values', ['attribute_id' => $width]))->toBe([$value, $next])
            ->and(catalogOrdersPositions('catalog.attribute_values', ['attribute_id' => $width]))->toBe([1, 2])
            // Another attribute's values are not this list.
            ->and(DB::table('catalog.attribute_values')->where('id', $other)->value('position'))->toBe(1);
    });
});

describe('the warranties\' order (amendment 16(d))', function () {
    it('numbers the warranties already there in name order when they gain it', function () {
        $zeta = catalogOrdersWarranty('Zeta');
        $alpha = catalogOrdersWarranty('Alpha');
        $mid = catalogOrdersWarranty('Mid');
        $migration = require base_path('src/Modules/Catalog/Infrastructure/Persistence/Migrations/2026_10_10_120000_add_position_to_catalog_warranties.php');

        $migration->down();

        expect(DB::getSchemaBuilder()->hasColumn('catalog.warranties', 'position'))->toBeFalse();

        $migration->up();

        expect(DB::table('catalog.warranties')->orderBy('position')->pluck('position', 'id')->map(fn ($position): int => (int) $position)->all())
            ->toBe([$alpha => 1, $mid => 2, $zeta => 3]);
    });

    it('lists them in their order, the product form\'s choice', function () {
        $first = catalogOrdersWarranty('Zeta');
        $second = catalogOrdersWarranty('Alpha');

        expect(catalogOrdersOf('catalog.warranties'))->toBe([$first, $second]);

        Cx::actAsStaffWith([CatalogPermissions::WARRANTY_MANAGE]);
        app(OrderWarrantiesHandler::class)->handle(new OrderWarranties([$second]));

        expect(array_map(static fn (Warranty $warranty): string => $warranty->id(), app(WarrantyRepository::class)->all()))->toBe([$second, $first]);
    });
});
