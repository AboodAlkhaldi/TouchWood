<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Platform\Application\Menu\InMemoryAdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Sync\Application\SyncPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The admin menu's Sync section (sync.md §4.1.2, platform.md §9.4; S6): after Pricing, before Orders.
| Sync registers its entries with its screens, so the section is proved on a menu of the test's own,
| leaving the application's untouched.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('takes an entry under Sync and shows the section after Pricing and before Orders', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(
        new MenuEntryDto('sync', 'report', PermissionGroup::Sync->value, 'sync.test.report', SyncPermissions::REPORT_VIEW, 10, icon: 'sync'),
        new MenuEntryDto('test', 'orders', PermissionGroup::Orders->value, 'sync.test.orders', SyncPermissions::REPORT_VIEW, 10),
        new MenuEntryDto('test', 'prices', PermissionGroup::Pricing->value, 'sync.test.prices', PricingPermissions::PRICE_EDIT, 10),
    );

    Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW, PricingPermissions::PRICE_EDIT], ['sa']));

    expect(array_keys($menu->forCurrentActor()))->toBe(['pricing', 'sync', 'orders'])
        ->and(array_map(static fn (MenuEntryDto $entry): string => $entry->key, $menu->forCurrentActor()['sync'] ?? []))->toBe(['report']);
});

it('offers the Sync section only to someone holding one of its jobs', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(new MenuEntryDto('sync', 'report', PermissionGroup::Sync->value, 'sync.test.report', SyncPermissions::REPORT_VIEW, 10, icon: 'sync'));

    Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['sa']));
    expect(array_keys($menu->forCurrentActor()))->toBe(['sync']);

    Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));
    expect($menu->forCurrentActor())->toBe([]);
});

it('names the Sync section in both languages, and draws it with its own icon', function () {
    expect(__('access::permission_groups.sync', [], 'en'))->toBe('Sync')
        ->and(__('access::permission_groups.sync', [], 'ar'))->toBe('المزامنة')
        ->and((string) file_get_contents(base_path('resources/js/components/MenuIcon.tsx')))->toMatch('/^\s*sync: RefreshCw,$/m');
});
