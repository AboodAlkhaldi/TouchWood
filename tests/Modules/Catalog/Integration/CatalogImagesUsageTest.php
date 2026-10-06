<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A brand's logo and a category's photo as Platform media (catalog.md §2.4): uses that never block a
| delete. Deleting the file leaves the brand without a logo and the category without a photo, each
| audited — for someone who may change that list with All stores; anyone else is refused, and
| nothing changes.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Storage::fake('local');
});

it('reports a logo and a photo as uses that do not block, and detaches both', function () {
    Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE, CatalogPermissions::CATEGORY_MANAGE, PlatformPermissions::MEDIA_DELETE]);
    $image = Cx::media();
    $brand = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR', logoMediaId: $image));
    $category = app(AddCategoryHandler::class)->handle(new AddCategory('مطابخ', 'Kitchens', imageMediaId: $image));

    $queries = Cx::recordQueries();

    app(DeleteMediaHandler::class)->handle(new DeleteMedia($image));

    $sql = array_map(static fn (array $query): string => $query['sql'].' '.json_encode($query['bindings']), (array) $queries);
    $at = static function (string $needle) use ($sql): int {
        foreach ($sql as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        return -1;
    };
    $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.brand.logo_detached')->value('changes'), true);

    // Each list's lock before its rows change, categories' before brands' as every change takes them.
    expect($at('catalog:brands'))->toBeGreaterThan(-1)->toBeLessThan($at('update "catalog"."brands"'))
        ->and($at('catalog:categories'))->toBeLessThan($at('catalog:brands'))
        ->and($at('catalog:categories'))->toBeGreaterThan(-1)->toBeLessThan($at('update "catalog"."categories"'))
        ->and($changes)->toBe(['logo_media_id' => [$image, null]])
        ->and(DB::table('platform.media')->where('id', $image)->exists())->toBeFalse()
        ->and(DB::table('catalog.brands')->where('id', $brand)->value('logo_media_id'))->toBeNull()
        ->and(DB::table('catalog.categories')->where('id', $category)->value('image_media_id'))->toBeNull()
        ->and(Fx::audits('catalog.brand.logo_detached', $brand))->toBe(1)
        ->and(Fx::audits('catalog.category.image_detached', $category))->toBe(1);
});

it('refuses the delete, changing nothing, for someone who may not change the list that uses it', function (?string $alsoHeld) {
    Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE, CatalogPermissions::CATEGORY_MANAGE]);
    $image = Cx::media();
    $brand = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR', logoMediaId: $image));
    $category = app(AddCategoryHandler::class)->handle(new AddCategory('مطابخ', 'Kitchens', imageMediaId: $image));
    Cx::actAsStaffWith($alsoHeld === null ? [PlatformPermissions::MEDIA_DELETE] : [PlatformPermissions::MEDIA_DELETE, $alsoHeld]);

    expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($image)))->toThrow(Unauthorized::class)
        ->and(DB::table('platform.media')->where('id', $image)->exists())->toBeTrue()
        ->and(DB::table('catalog.brands')->where('id', $brand)->value('logo_media_id'))->toBe($image)
        ->and(DB::table('catalog.categories')->where('id', $category)->value('image_media_id'))->toBe($image);
})->with([
    'neither list' => [null],
    'brands only' => [CatalogPermissions::BRAND_MANAGE],
    'categories only' => [CatalogPermissions::CATEGORY_MANAGE],
]);

it('refuses someone holding the list\'s job in one store only', function () {
    Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
    $image = Cx::media();
    app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR', logoMediaId: $image));
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_DELETE, CatalogPermissions::BRAND_MANAGE], ['sa']));

    expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($image)))->toThrow(Unauthorized::class);
});

it('asks nothing of someone deleting a file Catalog does not use', function () {
    Cx::actAsStaffWith([PlatformPermissions::MEDIA_DELETE]);
    $image = Cx::media();

    app(DeleteMediaHandler::class)->handle(new DeleteMedia($image));

    expect(DB::table('platform.media')->where('id', $image)->exists())->toBeFalse();
});
