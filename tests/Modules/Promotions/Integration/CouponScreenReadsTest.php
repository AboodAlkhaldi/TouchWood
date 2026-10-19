<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\BrandDto;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\PromotionsActions\PromotionsActionsForReader;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Cp;

use function Pest\Laravel\seed;

/*
| What the coupon screens read besides Promotions' own queries (promotions.md §4.1): which Marketing
| buttons a reader may be offered in a store, and the picker's brands (catalog.md amendment 28).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('offers each Marketing button only in a store where the reader holds its job', function () {
    $sa = StoreId::fromString(Fx::storeId('sa'));
    $eg = StoreId::fromString(Fx::storeId('eg'));
    $actions = static fn (): PromotionsActionsForReader => app(PromotionsActionsForReader::class);

    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));
    $viewer = $actions()->forStore($sa);

    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::GIFT_MANAGE], ['sa']));
    $manager = $actions()->forStore($sa);
    $elsewhere = $actions()->forStore($eg);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $super = $actions()->forStore($eg);

    expect([$viewer->mayManageCoupons, $viewer->mayManageSegments, $viewer->mayManageGifts])->toBe([false, false, false])
        ->and([$manager->mayManageCoupons, $manager->mayManageSegments, $manager->mayManageGifts])->toBe([true, false, true])
        ->and([$elsewhere->mayManageCoupons, $elsewhere->mayManageGifts])->toBe([false, false])
        ->and([$super->mayManageCoupons, $super->mayManageSegments, $super->mayManageGifts])->toBe([true, true, true]);
});

it('lists the active brands in their order, leaving a switched-off one out', function () {
    $first = Cp::brand('Blum');
    $second = Cp::brand('Hettich');
    $off = Cp::brand('Old');
    DB::table('catalog.brands')->where('id', $off)->update(['is_active' => false]);

    $brands = app(CatalogApi::class)->activeBrands();

    expect(array_map(static fn (BrandDto $brand): string => $brand->id, $brands))->toBe([$first, $second])
        ->and(array_map(static fn (BrandDto $brand): bool => $brand->isActive, $brands))->toBe([true, true]);
});

it('needs no job to read the brands: the screens that use them guard themselves', function () {
    Cp::brand('Blum');
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa'], RoleLevel::Staff));

    expect(app(CatalogApi::class)->activeBrands())->toHaveCount(1);
});
