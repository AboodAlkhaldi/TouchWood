<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateAttribute\ActivateAttribute;
use Modules\Catalog\Application\Command\ActivateAttribute\ActivateAttributeHandler;
use Modules\Catalog\Application\Command\ActivateAttributeValue\ActivateAttributeValue;
use Modules\Catalog\Application\Command\ActivateAttributeValue\ActivateAttributeValueHandler;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttribute;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttributeHandler;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValue;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValueHandler;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttribute;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttributeHandler;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValue;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValueHandler;
use Modules\Catalog\Application\Command\EditAttribute\EditAttribute;
use Modules\Catalog\Application\Command\EditAttribute\EditAttributeHandler;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValue;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValueHandler;
use Modules\Catalog\Domain\Exception\AttributeKindLocked;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInUse;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The attribute library (catalog.md §1.7, amendment 1(i)): attributes and their jobs, values and
| their swatches (sets are gone, amendment 16(b)) — each under catalog.attribute.manage with All stores, each change
| audited by value under the attributes' lock.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function catalogAttributesAdd(string $nameEn, string $kind = 'VARIANT', array $overrides = []): string
{
    return app(AddAttributeHandler::class)->handle(new AddAttribute(...[
        'nameAr' => 'خاصية '.$nameEn,
        'nameEn' => $nameEn,
        'kind' => $kind,
        ...$overrides,
    ]));
}

/**
 * The form as it stands, with these fields changed.
 *
 * @param  array<string, mixed>  $changes
 */
function catalogAttributesEdit(string $attributeId, array $changes = []): void
{
    $attribute = app(AttributeRepository::class)->find($attributeId) ?? throw new LogicException('No such attribute.');

    app(EditAttributeHandler::class)->handle(new EditAttribute(...[
        'attributeId' => $attributeId,
        'nameAr' => $attribute->name()->ar,
        'nameEn' => $attribute->name()->en,
        'kind' => $attribute->kind()->value,
        'unitAr' => $attribute->unitAr(),
        'unitEn' => $attribute->unitEn(),
        'isColour' => $attribute->isColour(),
        'position' => $attribute->position(),
        ...$changes,
    ]));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function catalogAttributesValue(string $attributeId, string $nameEn, array $overrides = []): string
{
    return app(AddAttributeValueHandler::class)->handle(new AddAttributeValue(...[
        'attributeId' => $attributeId,
        'nameAr' => 'قيمة '.$nameEn,
        'nameEn' => $nameEn,
        ...$overrides,
    ]));
}

/**
 * A product whose variants are made of these attributes (amendment 16(b)).
 *
 * @param  list<string>  $attributeIds
 */
function catalogAttributesOnProduct(string $nameEn, array $attributeIds): string
{
    $product = Px::product($nameEn);
    Px::variantAttributes($product, $attributeIds);

    return $product;
}

describe('who may change the library', function () {
    it('takes catalog.attribute.manage with All stores, and refuses it held in one store, or another job', function () {
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE], ['sa']);

        expect(fn () => catalogAttributesAdd('Width'))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([CatalogPermissions::LABEL_MANAGE]);

        expect(fn () => catalogAttributesAdd('Width'))->toThrow(Unauthorized::class)
            ->and(DB::table('catalog.attributes')->count())->toBe(0);

        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);
        $width = catalogAttributesAdd('Width');
        $value = catalogAttributesValue($width, '300 mm');
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE], ['sa', 'eg']);

        expect(fn () => catalogAttributesValue($width, '400 mm'))->toThrow(Unauthorized::class)
            ->and(fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($value, 'ق', 'v')))->toThrow(Unauthorized::class)
            ->and(fn () => app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($value)))->toThrow(Unauthorized::class)
            ->and(fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($width)))->toThrow(Unauthorized::class)
            ->and(fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($width)))->toThrow(Unauthorized::class);
    });
});

describe('attributes', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);
    });

    it('adds an attribute with its job and unit, audited by value under the attributes\' lock', function () {
        $locks = Cx::recordLocks();
        $id = catalogAttributesAdd('Width', 'FILTERABLE', ['unitAr' => 'مم', 'unitEn' => 'mm', 'position' => 2]);
        $attribute = app(AttributeRepository::class)->find($id);

        expect($attribute?->kind()->value)->toBe('FILTERABLE')
            ->and($attribute?->unitEn())->toBe('mm')
            ->and(Fx::audits('catalog.attribute.added', $id))->toBe(1)
            ->and(array_values(array_filter((array) $locks, static fn (array $lock): bool => $lock['key'] === 'catalog:attributes')))->toBe([['key' => 'catalog:attributes', 'level' => 2]]);
    });

    it('refuses a job it does not know, a unit in one language, and a colour shown in the details only', function () {
        expect(fn () => catalogAttributesAdd('Width', 'SIZE'))->toThrow(InvalidCatalogAttribute::class, 'kind')
            ->and(fn () => catalogAttributesAdd('Width', 'FILTERABLE', ['unitEn' => 'mm']))->toThrow(InvalidCatalogAttribute::class, 'unit_ar')
            ->and(fn () => catalogAttributesAdd('Material', 'INFORMATIONAL', ['isColour' => true]))->toThrow(InvalidCatalogAttribute::class, 'is_colour')
            ->and(DB::table('catalog.attributes')->count())->toBe(0);
    });

    it('changes the job, and being a colour, only while there are no values', function () {
        $finish = catalogAttributesAdd('Finish', 'FILTERABLE');
        catalogAttributesEdit($finish, ['kind' => 'VARIANT', 'isColour' => true]);

        expect(app(AttributeRepository::class)->find($finish)?->isColour())->toBeTrue();

        catalogAttributesValue($finish, 'Black', ['swatch' => '#000000']);

        expect(fn () => catalogAttributesEdit($finish, ['kind' => 'FILTERABLE']))->toThrow(AttributeKindLocked::class)
            ->and(fn () => catalogAttributesEdit($finish, ['isColour' => false]))->toThrow(AttributeKindLocked::class);

        // Its name, unit and place still change.
        catalogAttributesEdit($finish, ['nameEn' => 'Colour', 'position' => 3]);

        expect(app(AttributeRepository::class)->find($finish)?->name()->en)->toBe('Colour')
            ->and(Fx::audits('catalog.attribute.edited', $finish))->toBe(2);
    });

    it('keeps an attribute a product makes its variants of variant-making, even with no values', function () {
        $width = catalogAttributesAdd('Width');
        catalogAttributesOnProduct('Sizes', [$width]);

        expect(fn () => catalogAttributesEdit($width, ['kind' => 'FILTERABLE']))->toThrow(InvalidCatalogAttribute::class, 'kind');
    });

    it('records nothing for an edit that changes nothing', function () {
        $width = catalogAttributesAdd('Width');
        catalogAttributesEdit($width);

        expect(Fx::audits('catalog.attribute.edited', $width))->toBe(0);
    });

    it('deactivates and activates an attribute, each audited once', function () {
        $width = catalogAttributesAdd('Width');
        app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($width));
        app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute($width));

        expect(app(AttributeRepository::class)->find($width)?->isActive())->toBeFalse();

        app(ActivateAttributeHandler::class)->handle(new ActivateAttribute($width));

        expect(app(AttributeRepository::class)->find($width)?->isActive())->toBeTrue()
            ->and(Fx::audits('catalog.attribute.deactivated', $width))->toBe(1)
            ->and(Fx::audits('catalog.attribute.activated', $width))->toBe(1);
    });

    it('refuses deleting an attribute a product makes its variants of, and deletes another with its values, each audited', function () {
        $width = catalogAttributesAdd('Width');
        $product = catalogAttributesOnProduct('Sizes', [$width]);
        $height = catalogAttributesAdd('Height');
        $value = catalogAttributesValue($height, '700 mm');

        expect(fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($width)))->toThrow(ListItemInUse::class);

        app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($height));

        expect(DB::table('catalog.attributes')->where('id', $height)->exists())->toBeFalse()
            ->and(DB::table('catalog.attribute_values')->where('id', $value)->exists())->toBeFalse()
            ->and(Fx::audits('catalog.attribute.deleted', $height))->toBe(1)
            ->and(Fx::audits('catalog.attribute_value.deleted', $value))->toBe(1);

        DB::table('catalog.products')->where('id', $product)->delete();
        app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($width));

        expect(DB::table('catalog.attributes')->count())->toBe(0);
    });

    it('answers an attribute that does not exist as not found', function () {
        expect(fn () => app(DeactivateAttributeHandler::class)->handle(new DeactivateAttribute('01j8z3k4m5n6p7q8r9s0t1v2w3')))->toThrow(ListItemNotFound::class)
            ->and(fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute('not-an-id')))->toThrow(ListItemNotFound::class)
            ->and(fn () => catalogAttributesValue('01j8z3k4m5n6p7q8r9s0t1v2w3', 'Black'))->toThrow(ListItemNotFound::class);
    });
});

describe('values', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);
    });

    it('adds values to a filter or a variant-making attribute, and none to one shown in the details only', function () {
        $width = catalogAttributesAdd('Width', 'FILTERABLE');
        $id = catalogAttributesValue($width, '300 mm', ['position' => 1]);
        $material = catalogAttributesAdd('Material', 'INFORMATIONAL');

        expect(app(AttributeRepository::class)->findValue($id)?->attributeId())->toBe($width)
            ->and(Fx::audits('catalog.attribute_value.added', $id))->toBe(1)
            ->and(fn () => catalogAttributesValue($material, 'Oak'))->toThrow(InvalidCatalogAttribute::class, 'attribute');

        $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.attribute_value.added')->value('changes'), true);

        expect($changes)->toMatchArray(['attribute_id' => [null, $width], 'name_en' => [null, '300 mm']]);
    });

    it('never names two values of one attribute alike, ignoring letter case, in either language', function () {
        $finish = catalogAttributesAdd('Finish');
        $other = catalogAttributesAdd('Other');
        $black = catalogAttributesValue($finish, 'Black', ['nameAr' => 'أسود']);
        $white = catalogAttributesValue($finish, 'White', ['nameAr' => 'أبيض']);

        expect(fn () => catalogAttributesValue($finish, ' black ', ['nameAr' => 'داكن']))->toThrow(NameTaken::class)
            ->and(fn () => catalogAttributesValue($finish, 'Dark', ['nameAr' => 'أسود']))->toThrow(NameTaken::class)
            ->and(fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($white, 'أبيض', 'BLACK')))->toThrow(NameTaken::class)
            // Another attribute's values are another list.
            ->and(catalogAttributesValue($other, 'Black', ['nameAr' => 'أسود']))->toBeString();

        // A value keeps its own name, letter case changed.
        app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($black, 'أسود', 'BLACK'));

        expect(app(AttributeRepository::class)->findValue($black)?->name()->en)->toBe('BLACK');
    });

    it('takes a swatch on a colour attribute\'s values, required, and refuses one anywhere else', function () {
        $colour = catalogAttributesAdd('Colour', 'VARIANT', ['isColour' => true]);
        $width = catalogAttributesAdd('Width');
        $id = catalogAttributesValue($colour, 'Walnut', ['swatch' => '#5C4033']);

        expect(app(AttributeRepository::class)->findValue($id)?->swatch())->toBe('#5c4033')
            ->and(fn () => catalogAttributesValue($colour, 'Oak'))->toThrow(InvalidCatalogAttribute::class, 'swatch')
            ->and(fn () => catalogAttributesValue($colour, 'Oak', ['swatch' => 'brown']))->toThrow(InvalidCatalogAttribute::class, 'swatch')
            ->and(fn () => catalogAttributesValue($width, '300 mm', ['swatch' => '#000000']))->toThrow(InvalidCatalogAttribute::class, 'swatch')
            ->and(fn () => app(EditAttributeValueHandler::class)->handle(new EditAttributeValue($id, 'جوز', 'Walnut')))->toThrow(InvalidCatalogAttribute::class, 'swatch');
    });

    it('deactivates, activates and deletes a value, each audited', function () {
        $width = catalogAttributesAdd('Width');
        $id = catalogAttributesValue($width, '300 mm');

        app(DeactivateAttributeValueHandler::class)->handle(new DeactivateAttributeValue($id));
        expect(app(AttributeRepository::class)->findValue($id)?->isActive())->toBeFalse();

        app(ActivateAttributeValueHandler::class)->handle(new ActivateAttributeValue($id));
        app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($id));

        expect(DB::table('catalog.attribute_values')->count())->toBe(0)
            ->and(Fx::audits('catalog.attribute_value.deactivated', $id))->toBe(1)
            ->and(Fx::audits('catalog.attribute_value.activated', $id))->toBe(1)
            ->and(Fx::audits('catalog.attribute_value.deleted', $id))->toBe(1)
            ->and(fn () => app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($id)))->toThrow(ListItemNotFound::class);
    });
});

describe('what the database refuses behind the code', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);
    });

    it('refuses a second value named alike, in either language, and a bad swatch', function () {
        $width = catalogAttributesAdd('Width');
        $value = catalogAttributesValue($width, '300 mm', ['nameAr' => 'ثلاثمئة']);
        $row = (array) DB::table('catalog.attribute_values')->where('id', $value)->sole();

        expect(fn () => DB::transaction(fn () => DB::table('catalog.attribute_values')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'name_ar' => 'أخرى', 'name_en' => '300 MM'])))
            ->toThrow(QueryException::class, 'attribute_values_name_en_unique')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.attribute_values')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'name_ar' => 'ثلاثمئة', 'name_en' => 'Other'])))
            ->toThrow(QueryException::class, 'attribute_values_name_ar_unique')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.attribute_values')->where('id', $value)->update(['swatch' => '#GGGGGG'])))
            ->toThrow(QueryException::class, 'attribute_values_swatch');
    });

    it('refuses deleting an attribute that still has a value, or that a product makes its variants of (RESTRICT)', function () {
        $width = catalogAttributesAdd('Width');
        catalogAttributesValue($width, '300 mm');
        $depth = catalogAttributesAdd('Depth');
        catalogAttributesOnProduct('Sizes', [$depth]);

        expect(fn () => DB::transaction(fn () => DB::table('catalog.attributes')->where('id', $width)->delete()))
            ->toThrow(QueryException::class, 'attribute_values_attribute')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.attributes')->where('id', $depth)->delete()))
            ->toThrow(QueryException::class, 'product_attributes_attribute');
    });

    it('refuses a colour shown in the details only, and an unknown job', function (array $values, string $constraint) {
        $width = catalogAttributesAdd('Width', 'INFORMATIONAL');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.attributes')->where('id', $width)->update($values)))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'a colour shown in the details only' => [['is_colour' => true], 'attributes_colour_has_values'],
        'an unknown job' => [['kind' => 'SIZE'], 'attributes_kind'],
        'a unit in one language' => [['unit_en' => 'mm'], 'attributes_unit_both'],
    ]);
});
