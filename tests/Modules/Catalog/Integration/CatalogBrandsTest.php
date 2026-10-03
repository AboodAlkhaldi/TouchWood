<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrand;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrandHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrand;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrandHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\MakeBrandDefault\MakeBrandDefault;
use Modules\Catalog\Application\Command\MakeBrandDefault\MakeBrandDefaultHandler;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\DefaultBrandRequired;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Brands (catalog.md §1.6, amendment 1(c), (j)): the TouchWood seed, adding, editing, slugs and their
| history, the one default, deactivating and deleting — each under catalog.brand.manage with All
| stores, each audited by value, under the brands' lock.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function catalogBrandsAdd(string $nameEn, array $overrides = []): string
{
    return app(AddBrandHandler::class)->handle(new AddBrand(...[
        // A number from the English name, so each Arabic name gives its own Arabic slug (§5.3:
        // Arabic letters and digits only).
        'nameAr' => 'ماركة '.sprintf('%u', crc32($nameEn)),
        'nameEn' => $nameEn,
        'agencyType' => 'DISTRIBUTOR',
        ...$overrides,
    ]));
}

/**
 * The form as it stands, with these fields changed.
 *
 * @param  array<string, mixed>  $changes
 */
function catalogBrandsEdit(string $brandId, array $changes = []): void
{
    $brand = app(BrandRepository::class)->find($brandId) ?? throw new LogicException('No such brand.');

    app(EditBrandHandler::class)->handle(new EditBrand(...[
        'brandId' => $brandId,
        'nameAr' => $brand->name()->ar,
        'nameEn' => $brand->name()->en,
        'agencyType' => $brand->agencyType()->value,
        'showInDefaultListings' => $brand->showInDefaultListings(),
        'position' => $brand->position(),
        'slugAr' => $brand->slugs()->ar->value,
        'slugEn' => $brand->slugs()->en->value,
        'descriptionAr' => $brand->descriptionAr()?->toArray(),
        'descriptionEn' => $brand->descriptionEn()?->toArray(),
        'logoMediaId' => $brand->logoMediaId(),
        'originCountry' => $brand->originCountry(),
        ...$changes,
    ]));
}

function catalogBrandsDefaultId(): ?string
{
    $id = DB::table('catalog.brands')->where('is_default', true)->value('id');

    return $id === null ? null : (string) $id;
}

describe('the seed', function () {
    it('creates TouchWood alone, as the house brand and the default, with its two slugs', function () {
        seed(CatalogSeeder::class);

        $brand = DB::table('catalog.brands')->sole();
        $slugs = DB::table('catalog.brand_slugs')->where('brand_id', $brand->id)->where('is_current', true)->pluck('slug', 'locale')->all();

        expect($brand->name_ar)->toBe('تاتش وود')
            ->and($brand->name_en)->toBe('TouchWood')
            ->and($brand->agency_type)->toBe('HOUSE')
            ->and($brand->origin_country)->toBe('SA')
            ->and((bool) $brand->is_default)->toBeTrue()
            ->and((bool) $brand->show_in_default_listings)->toBeTrue()
            ->and($slugs)->toEqualCanonicalizing(['ar' => 'تاتش-وود', 'en' => 'touchwood']);
    });

    it('adds nothing the second time', function () {
        seed(CatalogSeeder::class);
        seed(CatalogSeeder::class);

        expect(DB::table('catalog.brands')->count())->toBe(1);
    });

    it('makes TouchWood the default when staff added a brand before it', function () {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
        $blum = catalogBrandsAdd('Blum');

        seed(CatalogSeeder::class);

        expect(DB::table('catalog.brands')->where('is_default', true)->value('name_en'))->toBe('TouchWood')
            ->and(DB::table('catalog.brands')->where('id', $blum)->value('is_default'))->toBeFalse();
    });
});

describe('who may change brands', function () {
    it('takes the job with All stores, and refuses it held in one store only', function () {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE], ['sa']);

        expect(fn () => catalogBrandsAdd('Blum'))->toThrow(Unauthorized::class);
        expect(DB::table('catalog.brands')->count())->toBe(0);

        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);

        expect(catalogBrandsAdd('Blum'))->toBeString();
    });

    it('refuses someone holding another job', function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);

        expect(fn () => catalogBrandsAdd('Blum'))->toThrow(Unauthorized::class);
    });
});

describe('adding and editing', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
    });

    it('adds a brand with slugs made from its names, audited by value under the brands\' lock', function () {
        $locks = Cx::recordLocks();
        $id = catalogBrandsAdd('Häfele', ['nameAr' => 'هيفيله', 'originCountry' => ' de ', 'position' => 4]);
        $brand = app(BrandRepository::class)->find($id);

        expect($brand?->slugs()->en->value)->toBe('hafele')
            ->and($brand?->slugs()->ar->value)->toBe('هيفيله')
            ->and($brand?->originCountry())->toBe('DE')
            ->and(Fx::audits('catalog.brand.added', $id))->toBe(1)
            // Taken inside the handler's own transaction: level 2 under RefreshDatabase's.
            ->and(array_values(array_filter((array) $locks, static fn (array $lock): bool => $lock['key'] === 'catalog:brands')))->toBe([['key' => 'catalog:brands', 'level' => 2]]);

        $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.brand.added')->value('changes'), true);

        expect($changes)->toMatchArray(['name_en' => [null, 'Häfele'], 'slug_en' => [null, 'hafele'], 'agency_type' => [null, 'DISTRIBUTOR']]);
    });

    it('makes the first brand the default where there is none, and only the first', function () {
        $first = catalogBrandsAdd('Blum');
        $second = catalogBrandsAdd('Hettich');

        expect(catalogBrandsDefaultId())->toBe($first)->and($second)->not->toBe($first);
    });

    it('refuses a slug another brand holds, or ever held', function () {
        $hettich = catalogBrandsAdd('Hettich');
        $blum = catalogBrandsAdd('Blum');

        expect(fn () => catalogBrandsAdd('Hettich', ['nameAr' => 'أخرى']))->toThrow(SlugTaken::class);

        // Blum moves to a new English slug; its old one stays held, redirecting.
        catalogBrandsEdit($blum, ['slugEn' => 'blum-hinges']);

        expect(fn () => catalogBrandsEdit($hettich, ['slugEn' => 'blum']))->toThrow(SlugTaken::class)
            ->and(DB::table('catalog.brand_slugs')->where('brand_id', $blum)->where('locale', 'en')->pluck('is_current', 'slug')->map(fn ($v) => (bool) $v)->all())
            ->toEqualCanonicalizing(['blum' => false, 'blum-hinges' => true]);
    });

    it('lets a brand take back a slug it once held', function () {
        $blum = catalogBrandsAdd('Blum');
        catalogBrandsEdit($blum, ['slugEn' => 'blum-hinges']);
        catalogBrandsEdit($blum, ['slugEn' => 'blum']);

        expect(app(BrandRepository::class)->find($blum)?->slugs()->en->value)->toBe('blum')
            ->and(DB::table('catalog.brand_slugs')->where('brand_id', $blum)->where('locale', 'en')->count())->toBe(2);
    });

    it('records only what changed, and nothing for an edit that changes nothing', function () {
        $id = catalogBrandsAdd('Blum');
        catalogBrandsEdit($id);

        expect(Fx::audits('catalog.brand.edited', $id))->toBe(0);

        catalogBrandsEdit($id, ['position' => 7, 'showInDefaultListings' => false]);
        $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.brand.edited')->value('changes'), true);

        expect($changes)->toBe(['position' => [0, 7], 'show_in_default_listings' => [true, false]]);
    });

    it('takes a description in both languages or neither, and counts a bold word as a change', function () {
        $id = catalogBrandsAdd('Blum');
        $plain = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Austrian']]]]];
        $bold = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Austrian', 'bold' => true]]]]];

        expect(fn () => catalogBrandsEdit($id, ['descriptionEn' => $plain]))->toThrow(InvalidCatalogAttribute::class, 'description_ar');

        catalogBrandsEdit($id, ['descriptionAr' => $plain, 'descriptionEn' => $plain]);
        catalogBrandsEdit($id, ['descriptionAr' => $plain, 'descriptionEn' => $bold]);

        expect(Fx::audits('catalog.brand.edited', $id))->toBe(2)
            ->and(app(BrandRepository::class)->find($id)?->descriptionEn()?->toArray())->toBe($bold);
    });

    it('takes a public image as its logo, and refuses a private file or an unknown id', function () {
        $id = catalogBrandsAdd('Blum', ['logoMediaId' => $logo = Cx::media()]);

        expect(app(BrandRepository::class)->find($id)?->logoMediaId())->toBe($logo)
            ->and(fn () => catalogBrandsAdd('Hettich', ['logoMediaId' => Cx::media('PRIVATE')]))->toThrow(InvalidCatalogAttribute::class, 'logo_media_id')
            ->and(fn () => catalogBrandsAdd('Hettich', ['logoMediaId' => Cx::media('PUBLIC', 'application/pdf')]))->toThrow(InvalidCatalogAttribute::class, 'logo_media_id')
            ->and(fn () => catalogBrandsAdd('Hettich', ['logoMediaId' => '01j8z3k4m5n6p7q8r9s0t1v2w3']))->toThrow(InvalidCatalogAttribute::class, 'logo_media_id');
    });

    it('takes no origin country, or a two-letter code', function () {
        expect(app(BrandRepository::class)->find(catalogBrandsAdd('Blum'))?->originCountry())->toBeNull()
            ->and(fn () => catalogBrandsAdd('Hettich', ['originCountry' => 'Germany']))->toThrow(InvalidCatalogAttribute::class, 'origin_country');
    });

    it('answers a brand that does not exist as not found, an id or not', function () {
        // Every brand change with a well-formed id that is not in the list: CatalogListGuardsTest.
        expect(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand('not-an-id')))->toThrow(BrandNotFound::class);
    });

    it('refuses an Arabic slug another brand holds', function () {
        catalogBrandsAdd('Blum', ['nameAr' => 'بلوم']);

        expect(fn () => catalogBrandsAdd('Blum hinges', ['nameAr' => 'بلوم']))->toThrow(SlugTaken::class, 'بلوم');
    });

    it('takes a description in Arabic only as missing its English side', function () {
        $id = catalogBrandsAdd('Blum');
        $plain = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'نمساوية']]]]];

        expect(fn () => catalogBrandsEdit($id, ['descriptionAr' => $plain]))->toThrow(InvalidCatalogAttribute::class, 'description_en');
    });

    it('reads a blank origin country as none', function () {
        expect(app(BrandRepository::class)->find(catalogBrandsAdd('Blum', ['originCountry' => '  ']))?->originCountry())->toBeNull();
    });
});

describe('the one default', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
    });

    it('moves the mark: the old default is un-marked in the same step', function () {
        $first = catalogBrandsAdd('Blum');
        $second = catalogBrandsAdd('Hettich');

        app(MakeBrandDefaultHandler::class)->handle(new MakeBrandDefault($second));

        expect(catalogBrandsDefaultId())->toBe($second)
            ->and(DB::table('catalog.brands')->where('is_default', true)->count())->toBe(1)
            ->and(Fx::audits('catalog.brand.default_moved', $first))->toBe(1)
            ->and(Fx::audits('catalog.brand.made_default', $second))->toBe(1);
    });

    it('never makes an inactive brand the default', function () {
        catalogBrandsAdd('Blum');
        $second = catalogBrandsAdd('Hettich');
        app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($second));

        expect(fn () => app(MakeBrandDefaultHandler::class)->handle(new MakeBrandDefault($second)))->toThrow(BrandInactive::class);
    });

    it('refuses deactivating or deleting the default, and takes either for another brand', function () {
        $default = catalogBrandsAdd('Blum');
        $other = catalogBrandsAdd('Hettich');

        expect(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($default)))->toThrow(DefaultBrandRequired::class)
            ->and(fn () => app(DeleteBrandHandler::class)->handle(new DeleteBrand($default)))->toThrow(DefaultBrandRequired::class);

        app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($other));
        expect(app(BrandRepository::class)->find($other)?->isActive())->toBeFalse();

        app(ActivateBrandHandler::class)->handle(new ActivateBrand($other));
        expect(app(BrandRepository::class)->find($other)?->isActive())->toBeTrue()
            ->and(Fx::audits('catalog.brand.deactivated', $other))->toBe(1)
            ->and(Fx::audits('catalog.brand.activated', $other))->toBe(1);

        app(DeleteBrandHandler::class)->handle(new DeleteBrand($other));
        expect(DB::table('catalog.brands')->where('id', $other)->exists())->toBeFalse()
            ->and(Fx::audits('catalog.brand.deleted', $other))->toBe(1);
    });
});

describe('what the database refuses behind the code', function () {
    it('keeps at most one default brand', function () {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
        catalogBrandsAdd('Blum');
        $second = catalogBrandsAdd('Hettich');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.brands')->where('id', $second)->update(['is_default' => true])))
            ->toThrow(QueryException::class, 'brands_one_default');
    });

    it('refuses an inactive default, a bad country and an agency type it does not know', function (array $values, string $constraint) {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
        $id = catalogBrandsAdd('Blum');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.brands')->where('id', $id)->update($values)))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'an inactive default' => [['is_active' => false], 'brands_default_active'],
        // Two letters, so only the CHECK can refuse it — not the column's length (lesson 112).
        'a lower-case country' => [['origin_country' => 'de'], 'brands_origin_country'],
        'an unknown agency type' => [['agency_type' => 'RESELLER'], 'brands_agency_type'],
    ]);

    it('gives a slug to one brand only, current or old', function () {
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
        catalogBrandsAdd('Blum');
        $other = catalogBrandsAdd('Hettich');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.brand_slugs')->insert(['locale' => 'en', 'slug' => 'blum', 'brand_id' => $other, 'is_current' => false, 'created_at' => now()])))
            ->toThrow(QueryException::class, 'brand_slugs_pkey');
    });
});
