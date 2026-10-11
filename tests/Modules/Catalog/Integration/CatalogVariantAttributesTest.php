<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddValueFromProduct\AddValueFromProduct;
use Modules\Catalog\Application\Command\AddValueFromProduct\AddValueFromProductHandler;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttribute;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttributeHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttribute;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttributeHandler;
use Modules\Catalog\Application\Command\EditAttribute\EditAttribute;
use Modules\Catalog\Application\Command\EditAttribute\EditAttributeHandler;
use Modules\Catalog\Application\Command\OrderVariantAttributes\OrderVariantAttributes;
use Modules\Catalog\Application\Command\OrderVariantAttributes\OrderVariantAttributesHandler;
use Modules\Catalog\Application\Command\RemoveVariantAttribute\RemoveVariantAttribute;
use Modules\Catalog\Application\Command\RemoveVariantAttribute\RemoveVariantAttributeHandler;
use Modules\Catalog\Domain\Exception\DuplicateCombination;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| A product's variant attributes (catalog.md §1.7, P27, P28; owner, 2026-10-09 and 2026-10-10,
| amendment 16(b), (c)): "Has Variants: Yes" is having one; any of the library's variant-making
| attributes, every variant — archived ones too — given its value when one is added, one removed only
| while the variants stay apart, their order dragged; a new value made from the product's Variants
| tab by whoever may change the product.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);
});

/** @return list<string> */
function catalogVariantAttributesOf(string $productId): array
{
    return app(ProductRepository::class)->variantAttributes($productId);
}

/**
 * @param  array<string, string>  $values
 */
function catalogVariantAttributesAdd(string $productId, string $attributeId, array $values = []): void
{
    app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($productId, $attributeId, $values));
}

/** @return array<string, string> attribute id => value id */
function catalogVariantAttributesValues(string $variantId): array
{
    return app(VariantRepository::class)->find($variantId)?->combination()->valueIds ?? [];
}

describe('Has Variants: Yes', function () {
    it('gives a product with one variant its first attribute, the variant given its value, audited once', function () {
        $drawer = Px::product();
        $variant = Px::variant($drawer, '1304');
        $width = Px::attribute('Width');
        $sixty = Px::value($width, '60 cm');

        expect(catalogVariantAttributesOf($drawer))->toBe([]);

        catalogVariantAttributesAdd($drawer, $width, [strtoupper($variant) => $sixty]);

        expect(catalogVariantAttributesOf($drawer))->toBe([$width])
            ->and(catalogVariantAttributesValues($variant))->toBe([$width => $sixty])
            ->and(Fx::audits('catalog.product.variant_attribute_added', $drawer))->toBe(1);

        // Now a second size has its own values, so it comes in.
        $eighty = Px::variant($drawer, '1305', [$width => Px::value($width, '80 cm')]);

        expect(catalogVariantAttributesValues($eighty))->toHaveKey($width);
    });

    it('asks a value of it for every variant, archived ones too, and refuses one missing, of another attribute or deactivated', function () {
        ['product' => $drawer, 'variants' => [$sixty, $eighty], 'width' => $width] = Px::ready(['60 cm', '80 cm']);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($eighty)));
        $finish = Px::attribute('Finish');
        [$zinc, $black] = [Px::value($finish, 'Zinc'), Px::value($finish, 'Black')];
        $other = Px::value(Px::attribute('Depth'), '50 cm');
        $old = Px::value($finish, 'Old');
        $retired = Px::attribute('Retired');
        Fx::asSystem(fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($retired)));

        expect(fn () => catalogVariantAttributesAdd($drawer, $finish, [$sixty => $zinc]))->toThrow(InvalidCatalogAttribute::class, 'archived ones too')
            ->and(fn () => catalogVariantAttributesAdd($drawer, $finish, [$sixty => $zinc, $eighty => $other]))->toThrow(InvalidCatalogAttribute::class, 'values of')
            ->and(fn () => catalogVariantAttributesAdd($drawer, $width, [$sixty => $zinc, $eighty => $black]))->toThrow(InvalidCatalogAttribute::class, 'not made of yet')
            ->and(fn () => catalogVariantAttributesAdd($drawer, Px::attribute('Use', 'FILTERABLE'), []))->toThrow(InvalidCatalogAttribute::class, 'makes variants')
            ->and(fn () => catalogVariantAttributesAdd($drawer, $retired, []))->toThrow(ListItemInactive::class);

        DB::table('catalog.attribute_values')->where('id', $old)->update(['is_active' => false]);

        expect(fn () => catalogVariantAttributesAdd($drawer, $finish, [$sixty => $zinc, $eighty => $old]))->toThrow(ListItemInactive::class)
            ->and(catalogVariantAttributesOf($drawer))->toBe([$width]);

        catalogVariantAttributesAdd($drawer, $finish, [$sixty => $zinc, $eighty => $black]);

        expect(catalogVariantAttributesOf($drawer))->toBe([$width, $finish])
            ->and(catalogVariantAttributesValues($eighty))->toHaveKey($finish)
            ->and(catalogVariantAttributesValues($eighty)[$finish])->toBe($black);
    });

    it('takes at most ten attributes, and needs the product\'s job where it is on', function () {
        $drawer = Px::product();
        Px::variantAttributes($drawer, array_map(static fn (int $n): string => Px::attribute("A{$n}"), range(1, 10)));

        expect(fn () => catalogVariantAttributesAdd($drawer, Px::attribute('Eleventh')))->toThrow(InvalidCatalogAttribute::class, 'at most 10');

        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);

        expect(fn () => catalogVariantAttributesAdd(Px::product(), Px::attribute('Width')))->toThrow(Unauthorized::class);

        // On in Egypt, where the reader does not hold the product's job: none of the four lets them in.
        ['product' => $ready, 'variants' => [$variant], 'width' => $width] = Px::ready(['60 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('eg'), $ready, true)));
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa']);
        $finish = Px::attribute('Finish');

        expect(fn () => catalogVariantAttributesAdd($ready, $finish, [$variant => Px::value($finish, 'Zinc')]))->toThrow(Unauthorized::class)
            ->and(fn () => app(RemoveVariantAttributeHandler::class)->handle(new RemoveVariantAttribute($ready, $width)))->toThrow(Unauthorized::class)
            ->and(fn () => app(OrderVariantAttributesHandler::class)->handle(new OrderVariantAttributes($ready, [$width])))->toThrow(Unauthorized::class)
            ->and(fn () => app(AddValueFromProductHandler::class)->handle(new AddValueFromProduct($ready, $width, 'قيمة', 'New')))->toThrow(Unauthorized::class);
    });

    it('keeps an attribute a product makes its variants of variant-making', function () {
        $width = Px::attribute('Width');
        Px::variantAttributes(Px::product(), [$width]);

        expect(fn () => Fx::asSystem(fn () => app(EditAttributeHandler::class)->handle(new EditAttribute($width, 'خاصية', 'Width', 'FILTERABLE'))))
            ->toThrow(InvalidCatalogAttribute::class, 'variant-making while products make their variants of it');
    });
});

describe('an attribute removed, and their order', function () {
    it('takes an attribute away while the variants stay apart, and the last only with one variant left: Has Variants: No', function () {
        $drawer = Px::product();
        [$width, $finish] = [Px::attribute('Width'), Px::attribute('Finish')];
        Px::variantAttributes($drawer, [$width, $finish]);
        $sixty = Px::value($width, '60 cm');
        $zinc = Px::value($finish, 'Zinc');
        $first = Px::variant($drawer, '1304', [$width => $sixty, $finish => $zinc]);
        $second = Px::variant($drawer, '1305', [$width => $sixty, $finish => Px::value($finish, 'Black')]);
        $remove = fn (string $attribute) => app(RemoveVariantAttributeHandler::class)->handle(new RemoveVariantAttribute($drawer, $attribute));

        // Without Finish the two would be alike.
        expect(fn () => $remove($finish))->toThrow(DuplicateCombination::class)
            ->and(fn () => $remove(Px::attribute('Depth')))->toThrow(InvalidCatalogAttribute::class, 'made of');

        $remove($width);

        expect(catalogVariantAttributesOf($drawer))->toBe([$finish])
            ->and(catalogVariantAttributesValues($first))->toBe([$finish => $zinc])
            ->and(Fx::audits('catalog.product.variant_attribute_removed', $drawer))->toBe(1)
            ->and(fn () => $remove($finish))->toThrow(DuplicateCombination::class);

        // An archived variant still counts: it may be restored.
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($second)));

        expect(fn () => $remove($finish))->toThrow(DuplicateCombination::class);

        DB::table('catalog.variants')->where('id', $second)->delete();
        $remove($finish);

        expect(catalogVariantAttributesOf($drawer))->toBe([])
            ->and(catalogVariantAttributesValues($first))->toBe([]);
    });

    it('orders them as sent, the rest after, rewriting no variant', function () {
        $drawer = Px::product();
        [$width, $finish, $depth] = [Px::attribute('Width'), Px::attribute('Finish'), Px::attribute('Depth')];
        Px::variantAttributes($drawer, [$width, $finish, $depth]);
        $variant = Px::variant($drawer, '1304', [$width => Px::value($width, '60 cm'), $finish => Px::value($finish, 'Zinc'), $depth => Px::value($depth, '50 cm')]);
        $combination = DB::table('catalog.variants')->where('id', $variant)->value('combination');
        $order = fn (string ...$ids) => app(OrderVariantAttributesHandler::class)->handle(new OrderVariantAttributes($drawer, array_values($ids)));

        $order($depth);

        expect(catalogVariantAttributesOf($drawer))->toBe([$depth, $width, $finish])
            ->and(DB::table('catalog.variants')->where('id', $variant)->value('combination'))->toBe($combination)
            ->and(Fx::audits('catalog.product.variant_attributes_ordered', $drawer))->toBe(1);

        $order($depth);

        expect(Fx::audits('catalog.product.variant_attributes_ordered', $drawer))->toBe(1)
            ->and(fn () => $order(Px::attribute('Other')))->toThrow(InvalidCatalogAttribute::class, 'made of');
    });
});

describe('a value made from the product', function () {
    it('adds it last in its attribute\'s order for whoever may change the product, never named twice', function () {
        $drawer = Px::product();
        $colour = Px::attribute('Colour');
        Px::value($colour, 'Black');
        $add = fn (string $en) => app(AddValueFromProductHandler::class)->handle(new AddValueFromProduct($drawer, $colour, "لون {$en}", $en));

        $grey = $add('Grey');

        expect(DB::table('catalog.attribute_values')->where('id', $grey)->value('position'))->toBe((int) DB::table('catalog.attribute_values')->where('attribute_id', $colour)->max('position'))
            ->and(DB::table('catalog.attribute_values')->where('attribute_id', $colour)->count())->toBe(2)
            ->and(Fx::audits('catalog.attribute_value.added', $grey))->toBe(1)
            ->and(fn () => $add('grey'))->toThrow(NameTaken::class);

        $use = Px::attribute('Use', 'FILTERABLE');
        $retired = Px::attribute('Retired');
        Fx::asSystem(fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($retired)));

        expect(fn () => app(AddValueFromProductHandler::class)->handle(new AddValueFromProduct($drawer, $use, 'مطبخ', 'Kitchen')))->toThrow(InvalidCatalogAttribute::class, 'makes variants')
            ->and(fn () => app(AddValueFromProductHandler::class)->handle(new AddValueFromProduct($drawer, $retired, 'قديم', 'Old')))->toThrow(ListItemInactive::class);

        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);

        expect(fn () => $add('White'))->toThrow(Unauthorized::class);
    });
});

describe('the migration from attribute sets (amendment 16(b), P34)', function () {
    it('stops on a products file not brought in that names a set, then gives each product its set\'s attributes in order, every variant rewritten in id order', function () {
        $migration = require base_path('src/Modules/Catalog/Infrastructure/Persistence/Migrations/2026_10_10_120000_give_catalog_products_their_own_variant_attributes.php');
        [$width, $finish] = [Px::attribute('Width'), Px::attribute('Finish')];
        $zinc = Px::value($finish, 'Zinc');
        $sixty = Px::value($width, '60 cm');
        $drawer = Px::product();
        $variant = Px::variant($drawer, '1304');

        // Back to the schema as it was: a set, Finish before Width, the product taking it.
        DB::statement('DROP TABLE catalog.product_attributes');
        DB::statement('CREATE TABLE catalog.attribute_sets (id char(26) PRIMARY KEY, name_ar text, name_en text, is_active boolean, created_at timestamptz, updated_at timestamptz)');
        DB::statement('CREATE TABLE catalog.attribute_set_members (attribute_set_id char(26), attribute_id char(26), position integer)');
        DB::statement('ALTER TABLE catalog.products ADD COLUMN attribute_set_id char(26)');
        $set = '01k6aaaaaaaaaaaaaaaaaaaaaa';
        DB::table('catalog.attribute_sets')->insert(['id' => $set, 'name_ar' => 'مقاسات', 'name_en' => 'Sizes', 'is_active' => true]);
        DB::table('catalog.attribute_set_members')->insert([['attribute_set_id' => $set, 'attribute_id' => $finish, 'position' => 1], ['attribute_set_id' => $set, 'attribute_id' => $width, 'position' => 2]]);
        DB::table('catalog.products')->where('id', $drawer)->update(['attribute_set_id' => $set]);
        DB::table('catalog.variant_values')->insert([['variant_id' => $variant, 'attribute_id' => $finish, 'value_id' => $zinc], ['variant_id' => $variant, 'attribute_id' => $width, 'value_id' => $sixty]]);
        // In the set's order, as it was kept.
        DB::table('catalog.variants')->where('id', $variant)->update(['combination' => "{$zinc},{$sixty}"]);
        $import = strtolower((string) Str::ulid());
        DB::table('catalog.imports')->insert(['id' => $import, 'kind' => 'PRODUCTS', 'file_name' => 'runners.json', 'state' => 'DECIDING', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog.import_products')->insert(['id' => strtolower((string) Str::ulid()), 'import_id' => $import, 'number' => 1, 'data' => json_encode(['name_ar' => 'مجرى', 'attribute_set' => 'Sizes', 'variants' => [['code' => '7001']]], JSON_THROW_ON_ERROR), 'state' => 'WAITING', 'codes' => '{7001}']);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, "import {$import} (runners.json): it names an attribute set");

        DB::table('catalog.imports')->where('id', $import)->update(['state' => 'IN']);
        // A name of a set still to decide stops it too; a product left out at upload does not.
        $named = strtolower((string) Str::ulid());
        DB::table('catalog.imports')->insert(['id' => $named, 'kind' => 'PRODUCTS', 'file_name' => 'hinges.json', 'state' => 'FAILED', 'failure' => 'Stopped.', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog.import_names')->insert(['id' => strtolower((string) Str::ulid()), 'import_id' => $named, 'kind' => 'SET', 'written' => 'Sizes', 'key' => str_repeat('a', 64), 'products' => 1]);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, "import {$named} (hinges.json): it names an attribute set");

        DB::table('catalog.import_names')->where('import_id', $named)->delete();
        DB::table('catalog.import_products')->insert(['id' => strtolower((string) Str::ulid()), 'import_id' => $named, 'number' => 1, 'data' => json_encode(['attribute_set' => 'Sizes', 'variants' => []], JSON_THROW_ON_ERROR), 'state' => 'REFUSED', 'refusal' => 'Its codes mix products.', 'codes' => '{7002}']);
        $migration->up();
        expect(DB::table('catalog.product_attributes')->where('product_id', $drawer)->orderBy('position')->pluck('attribute_id')->all())->toBe([$finish, $width])
            // Width was made before Finish: its value comes first, whatever the values' own ids.
            ->and(DB::table('catalog.variants')->where('id', $variant)->value('combination'))->toBe("{$sixty},{$zinc}")
            ->and(Schema::hasTable('catalog.attribute_sets'))->toBeFalse()
            ->and(Schema::hasColumn('catalog.products', 'attribute_set_id'))->toBeFalse();
    });
});
