<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Platform\Application\Menu\InMemoryAdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Promotions\Application\PromotionsPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The admin menu's Marketing section (promotions.md §2, platform.md §9.4; handoff §14). Promotions
| registers no entry yet — its screens come later — so the section is proved on a menu of the test's
| own, leaving the application's untouched.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('takes an entry under Marketing and shows the section after Customers and before Points', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(
        new MenuEntryDto('promotions', 'coupons', PermissionGroup::Marketing->value, 'promotions.test.coupons', PromotionsPermissions::VIEW, 10),
        new MenuEntryDto('test', 'points', PermissionGroup::Points->value, 'promotions.test.points', PromotionsPermissions::VIEW, 10),
        new MenuEntryDto('test', 'customers', PermissionGroup::Customers->value, 'promotions.test.customers', PromotionsPermissions::VIEW, 10),
    );

    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));

    expect(array_keys($menu->forCurrentActor()))->toBe(['customers', 'marketing', 'points'])
        ->and(array_map(static fn (MenuEntryDto $entry): string => $entry->key, $menu->forCurrentActor()['marketing'] ?? []))->toBe(['coupons']);
});

it('offers the Marketing section only to someone holding one of its jobs', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(new MenuEntryDto('promotions', 'coupons', PermissionGroup::Marketing->value, 'promotions.test.coupons', PromotionsPermissions::VIEW, 10));

    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));
    expect(array_keys($menu->forCurrentActor()))->toBe(['marketing']);

    Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));
    expect($menu->forCurrentActor())->toBe([]);
});
