<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Platform\Application\Menu\InMemoryAdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The admin menu's Points section (loyalty.md §2.3, platform.md §9.4; handoff §14: "Points settings ·
| Redemptions"). Loyalty registers no entry yet — its screens come later — so the section is proved on
| a menu of the test's own, leaving the application's untouched.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('takes an entry under Points and shows the section after Customers and before Staff', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(
        new MenuEntryDto('loyalty', 'redemptions', PermissionGroup::Points->value, 'loyalty.test.redemptions', LoyaltyPermissions::VIEW, 20),
        new MenuEntryDto('test', 'customers', PermissionGroup::Customers->value, 'loyalty.test.customers', LoyaltyPermissions::VIEW, 10),
        new MenuEntryDto('test', 'staff', PermissionGroup::StaffAndPermissions->value, 'loyalty.test.staff', LoyaltyPermissions::VIEW, 10),
    );

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa'], RoleLevel::Admin));

    $sections = array_keys($menu->forCurrentActor());

    expect($sections)->toBe(['customers', 'points', 'staff_and_permissions'])
        ->and(array_map(static fn (MenuEntryDto $entry): string => $entry->key, $menu->forCurrentActor()['points'] ?? []))->toBe(['redemptions']);
});

it('offers the Points section only to someone holding one of its jobs', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(new MenuEntryDto('loyalty', 'redemptions', PermissionGroup::Points->value, 'loyalty.test.redemptions', LoyaltyPermissions::VIEW, 20));

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));
    expect(array_keys($menu->forCurrentActor()))->toBe(['points']);

    Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));
    expect($menu->forCurrentActor())->toBe([]);
});
