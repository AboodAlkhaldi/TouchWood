<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| A category's description (shop-design.md §10.5; catalog.md amendment 48(d)): one line per language under its shop page's
| heading, each optional, 300 characters at most, set on the category's own form only (P23); a form that sends none keeps it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function categoryDescriptionEdit(string $id, array $with): void
{
    $row = DB::table('catalog.categories')->where('id', $id)->first();
    Fx::asSystem(fn () => app(EditCategoryHandler::class)->handle(new EditCategory(...['categoryId' => $id, 'nameAr' => (string) $row->name_ar, 'nameEn' => (string) $row->name_en, 'imageMediaId' => $row->image_media_id, ...$with])));
}

it('keeps a category\'s description per language, each optional, and keeps it when a form sends none', function () {
    $id = Px::category('Kitchens');

    categoryDescriptionEdit($id, ['descriptionAr' => 'كل ما يحتاجه مطبخك', 'descriptionEn' => null, 'withDescription' => true]);
    expect((array) DB::table('catalog.categories')->where('id', $id)->first(['description_ar', 'description_en']))->toBe(['description_ar' => 'كل ما يحتاجه مطبخك', 'description_en' => null]);

    categoryDescriptionEdit($id, []);
    expect(DB::table('catalog.categories')->where('id', $id)->value('description_ar'))->toBe('كل ما يحتاجه مطبخك');

    categoryDescriptionEdit($id, ['descriptionAr' => ' ', 'descriptionEn' => '', 'withDescription' => true]);
    expect(DB::table('catalog.categories')->where('id', $id)->value('description_ar'))->toBeNull();
});

it('refuses a description over 300 characters before the database does', function () {
    $id = Px::category('Kitchens');

    expect(fn () => categoryDescriptionEdit($id, ['descriptionEn' => str_repeat('a', 301), 'withDescription' => true]))->toThrow(InvalidCatalogAttribute::class)
        ->and(DB::table('catalog.categories')->where('id', $id)->value('description_en'))->toBeNull();
});
