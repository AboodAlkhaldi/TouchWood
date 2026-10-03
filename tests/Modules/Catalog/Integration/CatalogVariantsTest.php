<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttribute;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttributeHandler;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValue;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValueHandler;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariant;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariantHandler;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValue;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValueHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\DuplicateCombination;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\VariantDetail;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Variants and their codes (catalog.md §1.2, amendment 3): a code of digits belongs to one product,
| whose variants may share it, and stays with it until the product — a draft — is deleted; one value
| of every attribute of the product's set, a combination of its own; details as text or a number;
| in a draft, codes edited and variants deleted freely, a code given up free again.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, CatalogPermissions::VARIANT_CORRECT_CODE]);
});

/**
 * A draft whose set is one variant-making attribute with these values.
 *
 * @param  list<string>  $values
 * @return array{string, string, array<string, string>} the product, the attribute, value name => id
 */
function catalogVariantsSized(array $values = ['60 cm', '80 cm', '90 cm']): array
{
    $width = Px::attribute('Width');
    $ids = [];

    foreach ($values as $name) {
        $ids[$name] = Px::value($width, $name);
    }

    $product = Px::product();
    catalogVariantsSetOn($product, Px::set([$width]));

    return [$product, $width, $ids];
}

function catalogVariantsSetOn(string $productId, string $setId): void
{
    $product = app(ProductRepository::class)->find($productId) ?? throw new LogicException('No such product.');

    Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
        $productId, $product->name()->ar, $product->name()->en, $product->brandId(), attributeSetId: $setId,
    )));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function catalogVariantsAdd(string $productId, string $code, array $overrides = []): string
{
    return app(AddVariantHandler::class)->handle(new AddVariant(...['productId' => $productId, 'code' => $code, ...$overrides]));
}

/**
 * The variant's form as it stands, with these fields changed.
 *
 * @param  array<string, mixed>  $changes
 */
function catalogVariantsEdit(string $variantId, array $changes = []): void
{
    $variant = app(VariantRepository::class)->find($variantId) ?? throw new LogicException('No such variant.');

    app(UpdateVariantHandler::class)->handle(new UpdateVariant(...[
        'variantId' => $variantId,
        'code' => $variant->code()->value,
        'values' => $variant->combination()->valueIds,
        'details' => array_map(static fn (VariantDetail $detail): array => $detail->number === null ? ['text_ar' => (string) $detail->textAr, 'text_en' => (string) $detail->textEn] : ['number' => $detail->number], $variant->details()),
        'weightGrams' => $variant->measures()->weightGrams,
        'lengthMm' => $variant->measures()->lengthMm,
        'widthMm' => $variant->measures()->widthMm,
        'heightMm' => $variant->measures()->heightMm,
        'position' => $variant->position(),
        ...$changes,
    ]));
}

describe('codes', function () {
    it('takes digits only, 1 to 10 of them, trimmed', function () {
        $product = Px::product();

        expect(fn () => catalogVariantsAdd($product, 'BLM-110'))->toThrow(InvalidCatalogAttribute::class, 'code')
            ->and(fn () => catalogVariantsAdd($product, '12345678901'))->toThrow(InvalidCatalogAttribute::class, 'code')
            ->and(fn () => catalogVariantsAdd($product, ''))->toThrow(InvalidCatalogAttribute::class, 'code')
            ->and(app(VariantRepository::class)->find(catalogVariantsAdd($product, ' 1304 '))?->code()->value)->toBe('1304');
    });

    it('lets one product\'s sizes share a code, and never gives it to another product', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['80 cm']]]);

        expect(DB::table('catalog.variants')->where('code', '1304')->count())->toBe(2)
            ->and(DB::table('catalog.product_codes')->where('code', '1304')->value('product_id'))->toBe($drawer)
            ->and(fn () => catalogVariantsAdd(Px::product(), '1304'))->toThrow(CodeTaken::class, '1304');
    });

    it('corrects a code on every variant holding it, keeps the mistyped one with a ready product, frees it in a draft', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $sixty = catalogVariantsAdd($drawer, '1340', ['values' => [$width => $sizes['60 cm']]]);
        $eighty = catalogVariantsAdd($drawer, '1340', ['values' => [$width => $sizes['80 cm']]]);
        $other = catalogVariantsAdd($drawer, '1305', ['values' => [$width => $sizes['90 cm']]]);

        app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($sixty, '1304'));

        expect(DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->pluck('code')->unique()->values()->all())->toBe(['1304'])
            ->and(app(VariantRepository::class)->find($other)?->code()->value)->toBe('1305')
            ->and(Fx::audits('catalog.variant.code_corrected', $sixty))->toBe(1)
            ->and(Fx::audits('catalog.variant.code_corrected', $eighty))->toBe(1)
            // A draft: the mistyped code is free again.
            ->and(DB::table('catalog.product_codes')->where('code', '1340')->exists())->toBeFalse();

        DB::table('catalog.products')->where('id', $drawer)->update(['stage' => 'READY', 'category_id' => Px::category()]);
        app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($other, '1306'));

        // Ready: the mistyped code stays with the product, taken by no other.
        expect(DB::table('catalog.product_codes')->where('code', '1305')->value('product_id'))->toBe($drawer)
            ->and(fn () => catalogVariantsAdd(Px::product(), '1305'))->toThrow(CodeTaken::class);
    });

    it('refuses correcting to another product\'s code, and needs its own job', function () {
        $product = Px::product();
        $variant = catalogVariantsAdd($product, '1001');
        catalogVariantsAdd(Px::product(), '1002');

        expect(fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($variant, '1002')))->toThrow(CodeTaken::class);

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);

        expect(fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($variant, '1003')))->toThrow(Unauthorized::class);
    });

    it('edits a draft variant\'s code freely, the old one free again, and refuses it once ready', function () {
        $product = Px::product();
        $variant = catalogVariantsAdd($product, '1001');

        catalogVariantsEdit($variant, ['code' => '1009']);

        expect(app(VariantRepository::class)->find($variant)?->code()->value)->toBe('1009')
            ->and(DB::table('catalog.product_codes')->where('code', '1001')->exists())->toBeFalse()
            ->and(catalogVariantsAdd(Px::product(), '1001'))->toBeString();

        DB::table('catalog.products')->where('id', $product)->update(['stage' => 'READY', 'category_id' => Px::category()]);

        expect(fn () => catalogVariantsEdit($variant, ['code' => '1010']))->toThrow(InvalidStageChange::class);
    });
});

describe('values and combinations', function () {
    it('takes one active value of every attribute of the set, and no combination twice, archived included', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $other = Px::attribute('Colour');
        $black = Px::value($other, 'Black');
        catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);

        expect(fn () => catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]))->toThrow(DuplicateCombination::class)
            ->and(fn () => catalogVariantsAdd($drawer, '1304'))->toThrow(InvalidCatalogAttribute::class, 'values')
            ->and(fn () => catalogVariantsAdd($drawer, '1304', ['values' => [$width => $black]]))->toThrow(InvalidCatalogAttribute::class, 'values')
            ->and(fn () => catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['80 cm'], $other => $black]]))->toThrow(InvalidCatalogAttribute::class, 'values');

        DB::table('catalog.variants')->where('product_id', $drawer)->update(['is_archived' => true]);

        expect(fn () => catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]))->toThrow(DuplicateCombination::class);
    });

    it('takes no newly deactivated value, but keeps one a variant has', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $variant = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        Fx::asSystem(fn () => app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($sizes['60 cm'])));
        Fx::asSystem(fn () => app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($sizes['80 cm'])));

        catalogVariantsEdit($variant, ['position' => 3]);

        expect(app(VariantRepository::class)->find($variant)?->position())->toBe(3)
            ->and(fn () => catalogVariantsEdit($variant, ['values' => [$width => $sizes['80 cm']]]))->toThrow(ListItemInactive::class);
    });

    it('changes a variant\'s values, its combination still its own, and records nothing for no change', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $sixty = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['80 cm']]]);

        catalogVariantsEdit($sixty);

        expect(Fx::audits('catalog.variant.edited', $sixty))->toBe(0)
            ->and(fn () => catalogVariantsEdit($sixty, ['values' => [$width => $sizes['80 cm']]]))->toThrow(DuplicateCombination::class);

        catalogVariantsEdit($sixty, ['values' => [$width => $sizes['90 cm']]]);
        $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.variant.edited')->value('changes'), true);

        expect(app(VariantRepository::class)->find($sixty)?->combination()->valueIds)->toBe([$width => $sizes['90 cm']])
            ->and($changes)->toBe(['value_ids' => [$sizes['60 cm'], $sizes['90 cm']]]);
    });

    it('gives a product without a set one variant only', function () {
        $product = Px::product();
        catalogVariantsAdd($product, '1001');

        expect(fn () => catalogVariantsAdd($product, '1002'))->toThrow(DuplicateCombination::class);
    });
});

describe('details and measures', function () {
    it('takes text in both languages or a number, for details-only attributes', function () {
        $material = Px::attribute('Material', 'INFORMATIONAL');
        $load = Px::attribute('Load', 'INFORMATIONAL');
        $width = Px::attribute('Width', 'FILTERABLE');
        $product = Px::product();
        $variant = catalogVariantsAdd($product, '1001', ['details' => [$material => ['text_ar' => 'جلد', 'text_en' => 'Leather'], $load => ['number' => '25.50']]]);
        $details = app(VariantRepository::class)->find($variant)?->details() ?? [];

        expect($details[$material]->textEn)->toBe('Leather')
            ->and($details[$load]->number)->toBe('25.5')
            ->and(fn () => catalogVariantsEdit($variant, ['details' => [$width => ['number' => '1']]]))->toThrow(InvalidCatalogAttribute::class, 'details')
            ->and(fn () => catalogVariantsEdit($variant, ['details' => [$material => ['text_ar' => 'جلد']]]))->toThrow(InvalidCatalogAttribute::class, 'details')
            ->and(fn () => catalogVariantsEdit($variant, ['details' => [$load => ['number' => '1.2345']]]))->toThrow(InvalidCatalogAttribute::class, 'number');

        // The same number written another way is no change.
        catalogVariantsEdit($variant, ['details' => [$material => ['text_ar' => 'جلد', 'text_en' => 'Leather'], $load => ['number' => '025.500']]]);

        expect(Fx::audits('catalog.variant.edited', $variant))->toBe(0);
    });

    it('takes no newly deactivated details attribute, and measures 1 to 1,000,000', function () {
        $material = Px::attribute('Material', 'INFORMATIONAL');
        Fx::asSystem(fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($material)));
        $product = Px::product();

        expect(fn () => catalogVariantsAdd($product, '1001', ['details' => [$material => ['number' => '1']]]))->toThrow(ListItemInactive::class)
            ->and(fn () => catalogVariantsAdd($product, '1001', ['weightGrams' => 0]))->toThrow(InvalidCatalogAttribute::class, 'weight_grams')
            ->and(fn () => catalogVariantsAdd($product, '1001', ['heightMm' => 1_000_001]))->toThrow(InvalidCatalogAttribute::class, 'height_mm')
            ->and(app(VariantRepository::class)->find(catalogVariantsAdd($product, '1001', ['weightGrams' => 1_000_000]))?->measures()->weightGrams)->toBe(1_000_000);
    });
});

describe('deleting a draft\'s variant', function () {
    it('deletes it, frees its code when no other variant carries it, and only in a draft', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $sixty = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        $eighty = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['80 cm']]]);

        app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($sixty));

        expect(DB::table('catalog.product_codes')->where('code', '1304')->exists())->toBeTrue()
            ->and(Fx::audits('catalog.variant.deleted', $sixty))->toBe(1);

        app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($eighty));

        expect(DB::table('catalog.product_codes')->where('code', '1304')->exists())->toBeFalse();

        $ninety = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['90 cm']]]);
        DB::table('catalog.products')->where('id', $drawer)->update(['stage' => 'READY', 'category_id' => Px::category()]);

        expect(fn () => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($ninety)))->toThrow(InvalidStageChange::class);
    });
});

describe('what the database refuses behind the code', function () {
    it('refuses a code its product does not hold, letters in a code, and a combination twice', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $variant = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        catalogVariantsAdd(Px::product(), '1305');
        $row = (array) DB::table('catalog.variants')->where('id', $variant)->sole();

        expect(fn () => DB::transaction(fn () => DB::table('catalog.variants')->where('id', $variant)->update(['code' => '1305'])))
            ->toThrow(QueryException::class, 'variants_code_held')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.product_codes')->insert(['code' => '13a4', 'product_id' => $drawer, 'created_at' => now()])))
            ->toThrow(QueryException::class, 'product_codes_digits')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.variants')->insert([...$row, 'id' => strtolower((string) Str::ulid())])))
            ->toThrow(QueryException::class, 'variants_one_per_combination');
    });

    it('refuses a value of another attribute, and a detail half text half number', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $variant = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        $colour = Px::attribute('Colour');
        $black = Px::value($colour, 'Black');
        $material = Px::attribute('Material', 'INFORMATIONAL');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.variant_values')->where('variant_id', $variant)->update(['value_id' => $black])))
            ->toThrow(QueryException::class, 'variant_values_value')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.variant_details')->insert(['variant_id' => $variant, 'attribute_id' => $material, 'text_ar' => 'جلد', 'number' => 1])))
            ->toThrow(QueryException::class, 'variant_details_one_kind');
    });
});

describe('a draft variant\'s code edited', function () {
    it('refuses another product\'s code, and keeps one another variant still carries', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $sixty = catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['80 cm']]]);
        catalogVariantsAdd(Px::product(), '2001');

        expect(fn () => catalogVariantsEdit($sixty, ['code' => '2001']))->toThrow(CodeTaken::class);

        catalogVariantsEdit($sixty, ['code' => '1305']);

        expect(DB::table('catalog.product_codes')->where('product_id', $drawer)->orderBy('code')->pluck('code')->all())->toBe(['1304', '1305']);
    });
});

describe('a detail kept', function () {
    it('keeps a detail of an attribute deactivated since when its variant is edited', function () {
        $material = Px::attribute('Material', 'INFORMATIONAL');
        $variant = catalogVariantsAdd(Px::product(), '1001', ['details' => [$material => ['text_ar' => 'خشب', 'text_en' => 'Wood']]]);
        Fx::asSystem(fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($material)));

        catalogVariantsEdit($variant, ['weightGrams' => 500]);

        expect(array_keys(app(VariantRepository::class)->find($variant)?->details() ?? []))->toBe([$material])
            ->and(app(VariantRepository::class)->find($variant)?->measures()->weightGrams)->toBe(500);
    });
});

describe('the rows a variant points at', function () {
    it('locks them, each attribute before its value, as the value\'s own edit does', function () {
        [$drawer, $width, $sizes] = catalogVariantsSized();
        $queries = Cx::recordQueries();

        catalogVariantsAdd($drawer, '1304', ['values' => [$width => $sizes['60 cm']]]);
        $adding = Cx::lockedTables($queries);
        $queries->exchangeArray([]);
        Fx::asSystem(fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($sizes['60 cm'], 'ستون', '60 cm wide')));
        $editing = Cx::lockedTables($queries);

        expect(array_values(array_unique(array_intersect($adding, ['attributes', 'attribute_values']))))->toBe(['attributes', 'attribute_values'])
            ->and($editing)->toBe(['attributes', 'attribute_values']);
    });
});
