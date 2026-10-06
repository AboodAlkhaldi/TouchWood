<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarranty;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarrantyHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Deciding an import's names and codes (catalog.md §1.12, page parts 1 and 2; amendment 6(c), (d)): a
| Super Admin's; a name means one the catalog has — active, in that list, doing the job the file needs —
| is created with both its names, or is refused; a product whose code the catalog has updates or
| replaces that product, is skipped, or takes new codes no product has. Decided before bringing in, or
| again after it failed; several at once, all or none; each change audited. The catalog is not touched.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    Ix::cleanUp();
});

/**
 * @param  list<array<string, mixed>>  $decisions
 */
function catalogDecideNames(string $importId, array $decisions): void
{
    app(DecideImportNamesHandler::class)->handle(new DecideImportNames($importId, $decisions));
}

/**
 * @param  list<array<string, mixed>>  $decisions
 */
function catalogDecideCodes(string $importId, array $decisions): void
{
    app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($importId, $decisions));
}

/**
 * @return array<string, mixed>
 */
function catalogDecidedName(string $nameId): array
{
    return (array) (DB::table('catalog.import_names')->where('id', $nameId)->first(['decision', 'target_id', 'name_ar', 'name_en']) ?? throw new LogicException('No name.'));
}

function catalogDecideEnglish(string $table, string $id): string
{
    return (string) DB::table("catalog.{$table}")->where('id', $id)->value('name_en');
}

/**
 * @param  array{product: string, variants: list<string>, width: string}  $ready
 */
function catalogDecideCode(array $ready): string
{
    return (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
}

describe('who decides', function () {
    it('is a Super Admin\'s: no role decides, an admin holding every Catalog job included', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm'])]);
        $name = Ix::nameId($import, 'BRAND', 'Blumm');
        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());

        expect(fn () => catalogDecideNames($import, [['name_id' => $name, 'decision' => 'REFUSE']]))->toThrow(Unauthorized::class)
            ->and(fn () => catalogDecideCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'SKIP']]))->toThrow(Unauthorized::class);
        expect(catalogDecidedName($name)['decision'])->toBeNull();
    });
});

describe('a name the catalog lacks', function () {
    it('means one the catalog has: an active one of that list', function () {
        $brand = Px::brand('Blum');
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm'])]);
        $name = Ix::nameId($import, 'BRAND', 'Blumm');

        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'EXISTING', 'target_id' => strtoupper($brand)]]);

        expect(catalogDecidedName($name))->toBe(['decision' => 'EXISTING', 'target_id' => $brand, 'name_ar' => null, 'name_en' => null])
            ->and(Fx::audits('catalog.import_name.decided', $name))->toBe(1);
    });

    it('refuses one that is not in that list, or is deactivated', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm', 'warranty' => 'Two yeers'])]);
        $warranty = Px::warranty();
        Fx::asSystem(fn () => app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($warranty)));

        expect(fn () => catalogDecideNames($import, [['name_id' => Ix::nameId($import, 'BRAND', 'Blumm'), 'decision' => 'EXISTING', 'target_id' => $warranty]]))->toThrow(BrandNotFound::class)
            ->and(fn () => catalogDecideNames($import, [['name_id' => Ix::nameId($import, 'WARRANTY', 'Two yeers'), 'decision' => 'EXISTING', 'target_id' => $warranty]]))->toThrow(ListItemInactive::class)
            ->and(fn () => catalogDecideNames($import, [['name_id' => Ix::nameId($import, 'WARRANTY', 'Two yeers'), 'decision' => 'EXISTING']]))->toThrow(InvalidCatalogAttribute::class, 'Invalid decisions.0.target_id: required');
    });

    it('means an attribute doing the job the file uses it for', function () {
        $use = Px::attribute('Use', 'FILTERABLE');
        $width = Px::attribute('Width');
        $import = Ix::uploadProducts([Ix::product('1', ['filters' => ['Usage' => ['Kitchen']]])]);
        $name = Ix::nameId($import, 'ATTRIBUTE', 'Usage');

        expect(fn () => catalogDecideNames($import, [['name_id' => $name, 'decision' => 'EXISTING', 'target_id' => $width]]))->toThrow(InvalidCatalogAttribute::class, 'Invalid decisions.0.target_id: an attribute for filters');

        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'EXISTING', 'target_id' => $use]]);
        expect(catalogDecidedName($name)['target_id'])->toBe($use);
    });

    it('is created with its names in both languages, each on one line: a category or a set', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['category' => 'Hinges'])]);
        $name = Ix::nameId($import, 'CATEGORY', 'Hinges');

        expect(fn () => catalogDecideNames($import, [['name_id' => $name, 'decision' => 'CREATE', 'name_ar' => 'مفصلات']]))->toThrow(InvalidCatalogAttribute::class, 'Invalid decisions.0.name_en: required');

        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'CREATE', 'name_ar' => ' مفصلات ', 'name_en' => 'Hinges']]);
        expect(catalogDecidedName($name))->toBe(['decision' => 'CREATE', 'target_id' => null, 'name_ar' => 'مفصلات', 'name_en' => 'Hinges']);
    });

    it('is never created when it is a brand, a warranty or an attribute: those are added in the panel first', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Tallsen', 'warranty' => 'Two years', 'variants' => [['code' => '1', 'details' => ['Material' => ['ar' => 'فولاذ', 'en' => 'Steel']]]]])]);

        foreach ([['BRAND', 'Tallsen'], ['WARRANTY', 'Two years'], ['ATTRIBUTE', 'Material']] as [$kind, $written]) {
            expect(fn () => catalogDecideNames($import, [['name_id' => Ix::nameId($import, $kind, $written), 'decision' => 'CREATE', 'name_ar' => 'اسم', 'name_en' => 'Name']]))
                ->toThrow(InvalidCatalogAttribute::class, 'Invalid decisions.0.decision: EXISTING or REFUSE: brands, warranties and attributes are added in the panel first');
        }
    });

    it('is refused, and decided again as often as needed before bringing in, audited each time it changes', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['category' => 'Hinges'])]);
        $name = Ix::nameId($import, 'CATEGORY', 'Hinges');

        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'CREATE', 'name_ar' => 'مفصلات', 'name_en' => 'Hinges']]);
        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'REFUSE']]);
        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'REFUSE']]);

        expect(catalogDecidedName($name))->toBe(['decision' => 'REFUSE', 'target_id' => null, 'name_ar' => null, 'name_en' => null])
            ->and(Fx::audits('catalog.import_name.decided', $name))->toBe(2);
    });
});

describe('a value the catalog lacks', function () {
    it('means a value of its attribute, never another\'s, and is not created twice', function () {
        $width = Px::attribute('Width');
        $sixty = Px::value($width, '60 cm');
        $other = Px::value(Px::attribute('Depth'), '60 cm');
        $widthEn = catalogDecideEnglish('attributes', $width);
        $import = Ix::uploadProducts([Ix::product('1', ['attribute_set' => 'Sizes', 'variants' => [['code' => '1', 'values' => [$widthEn => '60cm']]]])]);
        $name = Ix::nameId($import, 'VALUE', '60cm');

        expect(fn () => catalogDecideNames($import, [['name_id' => $name, 'decision' => 'EXISTING', 'target_id' => $other]]))->toThrow(InvalidCatalogAttribute::class, "a value of {$widthEn}")
            ->and(fn () => catalogDecideNames($import, [['name_id' => $name, 'decision' => 'CREATE', 'name_ar' => 'ستون', 'name_en' => '60 CM']]))->toThrow(NameTaken::class);

        catalogDecideNames($import, [['name_id' => $name, 'decision' => 'EXISTING', 'target_id' => $sixty]]);
        expect(catalogDecidedName($name)['target_id'])->toBe($sixty);
    });

    it('under an attribute the catalog lacks, waits for that attribute to be decided as one it has', function () {
        $width = Px::attribute('Width');
        $sixty = Px::value($width, '60 cm');
        $import = Ix::uploadProducts([Ix::product('1', ['attribute_set' => 'Sizes', 'variants' => [['code' => '1', 'values' => ['Widht' => '60 cm']]]])]);
        $attribute = Ix::nameId($import, 'ATTRIBUTE', 'Widht');
        $value = Ix::nameId($import, 'VALUE', '60 cm');

        expect(fn () => catalogDecideNames($import, [['name_id' => $value, 'decision' => 'EXISTING', 'target_id' => $sixty]]))->toThrow(InvalidCatalogAttribute::class, 'once it is one the catalog has')
            ->and(fn () => catalogDecideNames($import, [['name_id' => $value, 'decision' => 'CREATE', 'name_ar' => 'ستون', 'name_en' => '61 cm']]))->toThrow(InvalidCatalogAttribute::class, 'a value of Widht is created once Widht is one the catalog has');

        // Together, in the order sent: the attribute first, then its value.
        catalogDecideNames($import, [
            ['name_id' => $attribute, 'decision' => 'EXISTING', 'target_id' => $width],
            ['name_id' => $value, 'decision' => 'EXISTING', 'target_id' => $sixty],
        ]);
        expect(catalogDecidedName($value)['target_id'])->toBe($sixty);

        // The attribute decided again: its value waits again.
        catalogDecideNames($import, [['name_id' => $attribute, 'decision' => 'EXISTING', 'target_id' => Px::attribute('Depth')]]);
        expect(catalogDecidedName($value))->toBe(['decision' => null, 'target_id' => null, 'name_ar' => null, 'name_en' => null])
            ->and(Fx::audits('catalog.import_name.decided', $value))->toBe(2);
    });
});

describe('a product whose code the catalog has', function () {
    it('updates that product, replaces it, or is skipped', function () {
        $ready = Px::ready();
        $import = Ix::uploadProducts([Ix::product(catalogDecideCode($ready))]);
        $product = Ix::productId($import, 1);

        foreach (['UPDATE', 'REPLACE', 'SKIP'] as $decision) {
            catalogDecideCodes($import, [['product_id' => $product, 'decision' => $decision]]);
            expect(DB::table('catalog.import_products')->where('id', $product)->value('decision'))->toBe($decision);
        }

        expect(Fx::audits('catalog.import_product.decided', $product))->toBe(3);
    });

    it('takes a new code for each code the catalog has, free in the catalog and in the file', function () {
        $ready = Px::ready();
        $other = Px::ready();
        $code = catalogDecideCode($ready);
        $import = Ix::uploadProducts([Ix::product($code), Ix::product('8800')]);
        $product = Ix::productId($import, 1);
        $recode = fn (array $newCodes) => catalogDecideCodes($import, [['product_id' => $product, 'decision' => 'RECODE', 'new_codes' => $newCodes]]);

        expect(fn () => catalogDecideCodes($import, [['product_id' => $product, 'decision' => 'RECODE']]))->toThrow(InvalidCatalogAttribute::class, "a new code for each code the catalog has: {$code}")
            ->and(fn () => $recode([$code => '8801', '9999' => '8802']))->toThrow(InvalidCatalogAttribute::class, 'a new code for each code the catalog has')
            ->and(fn () => $recode([$code => 'abc']))->toThrow(InvalidCatalogAttribute::class, "Invalid decisions.0.new_codes.{$code}")
            ->and(fn () => $recode([$code => '8800']))->toThrow(InvalidCatalogAttribute::class, 'a code no other product of the file has')
            ->and(fn () => $recode([$code => catalogDecideCode($other)]))->toThrow(CodeTaken::class)
            ->and(fn () => catalogDecideCodes($import, [['product_id' => $product, 'decision' => 'SKIP', 'new_codes' => [$code => '8801']]]))->toThrow(InvalidCatalogAttribute::class, 'only with RECODE');

        $recode([$code => '8801']);
        expect(json_decode((string) DB::table('catalog.import_products')->where('id', $product)->value('new_codes'), true))->toBe([(int) $code => '8801']);

        // Decided otherwise, it gives the new codes up.
        catalogDecideCodes($import, [['product_id' => $product, 'decision' => 'SKIP']]);
        expect(DB::table('catalog.import_products')->where('id', $product)->value('new_codes'))->toBeNull();
    });

    it('refuses a decision for a product whose codes the catalog does not have', function () {
        $import = Ix::uploadProducts([Ix::product('8800')]);

        expect(fn () => catalogDecideCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]))->toThrow(InvalidCatalogAttribute::class, 'Invalid decisions.0.product_id: a product whose code the catalog has');
    });
});

describe('every decision', function () {
    it('is all or none: a wrong one keeps the others from being saved', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Tallsen', 'warranty' => 'Two yeers'])]);
        $brand = Ix::nameId($import, 'BRAND', 'Tallsen');

        expect(fn () => catalogDecideNames($import, [
            ['name_id' => $brand, 'decision' => 'REFUSE'],
            ['name_id' => Ix::nameId($import, 'WARRANTY', 'Two yeers'), 'decision' => 'MAYBE'],
        ]))->toThrow(InvalidCatalogAttribute::class, 'Invalid decisions.1.decision: EXISTING, CREATE or REFUSE');
        expect(catalogDecidedName($brand)['decision'])->toBeNull();
    });

    it('names each once, from 1 to 500 at once', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Tallsen'])]);
        $brand = Ix::nameId($import, 'BRAND', 'Tallsen');

        expect(fn () => catalogDecideNames($import, []))->toThrow(InvalidCatalogAttribute::class, 'from 1 to 500 at once')
            ->and(fn () => catalogDecideNames($import, array_fill(0, 501, ['name_id' => $brand, 'decision' => 'REFUSE'])))->toThrow(InvalidCatalogAttribute::class, 'from 1 to 500 at once')
            ->and(fn () => catalogDecideNames($import, [['name_id' => $brand, 'decision' => 'REFUSE'], ['name_id' => strtoupper($brand), 'decision' => 'REFUSE']]))->toThrow(InvalidCatalogAttribute::class, 'each name once')
            ->and(fn () => catalogDecideCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'MERGE']]))->toThrow(InvalidCatalogAttribute::class, 'UPDATE, REPLACE, SKIP or RECODE');
    });

    it('belongs to the import it names', function () {
        $first = Ix::uploadProducts([Ix::product('1', ['brand' => 'Tallsen'])]);
        $second = Ix::uploadProducts([Ix::product('2', ['brand' => 'Tallsen'])]);

        expect(fn () => catalogDecideNames($second, [['name_id' => Ix::nameId($first, 'BRAND', 'Tallsen'), 'decision' => 'REFUSE']]))->toThrow(ListItemNotFound::class)
            ->and(fn () => catalogDecideNames('not-an-id', [['name_id' => Ix::nameId($first, 'BRAND', 'Tallsen'), 'decision' => 'REFUSE']]))->toThrow(ListItemNotFound::class)
            ->and(fn () => catalogDecideCodes($second, [['product_id' => Ix::productId($first, 1), 'decision' => 'SKIP']]))->toThrow(ListItemNotFound::class);
    });

    it('waits while the products are brought in or once they are, and reopens a failed bringing in', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Tallsen'])]);
        $brand = Ix::nameId($import, 'BRAND', 'Tallsen');

        foreach (['BRINGING_IN', 'IN'] as $state) {
            DB::table('catalog.imports')->where('id', $import)->update(['state' => $state]);
            expect(fn () => catalogDecideNames($import, [['name_id' => $brand, 'decision' => 'REFUSE']]))->toThrow(ImportClosed::class)
                ->and(fn () => catalogDecideCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'SKIP']]))->toThrow(ImportClosed::class);
        }

        DB::table('catalog.imports')->where('id', $import)->update(['state' => 'FAILED', 'failure' => 'Product 1: the brand Tallsen is deactivated.']);
        catalogDecideNames($import, [['name_id' => $brand, 'decision' => 'REFUSE']]);

        expect((array) DB::table('catalog.imports')->where('id', $import)->first(['state', 'failure']))->toBe(['state' => 'DECIDING', 'failure' => null]);
    });
});
