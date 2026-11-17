<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| How a product is sold (shop-design.md §10.1; catalog.md amendment 48(c)): one of a fixed list, a piece unless chosen,
| set on the product's own form; audited from and to; written onto its listing rows for the card's "SAR / per piece".
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function productUnitEdit(string $productId, ?string $unit): void
{
    $row = DB::table('catalog.products')->where('id', $productId)->first() ?? throw new LogicException('No product.');
    app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
        productId: $productId,
        nameAr: (string) $row->name_ar,
        nameEn: $row->name_en === null ? null : (string) $row->name_en,
        brandId: (string) $row->brand_id,
        descriptionAr: $row->description_ar === null ? null : json_decode((string) $row->description_ar, true),
        descriptionEn: $row->description_en === null ? null : json_decode((string) $row->description_en, true),
        categoryId: $row->category_id === null ? null : (string) $row->category_id,
        warrantyId: $row->warranty_id === null ? null : (string) $row->warranty_id,
        unit: $unit,
    ));
}

it('sells a product by the piece until another unit is chosen, audits the change, and writes it onto its listing rows', function () {
    $p = Px::ready(['60 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
    Px::onSale('sa', $p['product']);
    Fx::actAsStaff(Fx::staffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa']));

    expect(DB::table('catalog.products')->where('id', $p['product'])->value('unit'))->toBe('PIECE');

    productUnitEdit($p['product'], 'PAIR');
    expect(DB::table('catalog.products')->where('id', $p['product'])->value('unit'))->toBe('PAIR')
        ->and(DB::table('catalog.listing')->where('product_id', $p['product'])->pluck('unit')->unique()->values()->all())->toBe(['PAIR'])
        ->and(Fx::audits('catalog.product.edited', $p['product']))->toBeGreaterThanOrEqual(1);

    // None sent keeps it: the forms that predate the unit send none.
    productUnitEdit($p['product'], null);
    expect(DB::table('catalog.products')->where('id', $p['product'])->value('unit'))->toBe('PAIR');
});

it('refuses a unit outside the fixed list before the database does', function () {
    $p = Px::ready(['60 cm'], Px::category('Kitchens'));
    Fx::actAsStaff(Fx::staffWith([CatalogPermissions::PRODUCT_UPDATE], ['sa']));

    expect(fn () => productUnitEdit($p['product'], 'DOZEN'))->toThrow(InvalidCatalogAttribute::class)
        ->and(DB::table('catalog.products')->where('id', $p['product'])->value('unit'))->toBe('PIECE');
});
