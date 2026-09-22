<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * The entries the person acting now is offered, flattened to "group/key" so a test reads like the
 * menu does.
 *
 * @return list<string>
 */
function offeredMenu(): array
{
    $offered = [];

    foreach (app(AdminMenu::class)->forCurrentActor() as $group => $entries) {
        foreach ($entries as $entry) {
            $offered[] = "{$group}/{$entry->key}";
        }
    }

    return $offered;
}

function registerTestMenu(): void
{
    app(AdminMenu::class)->register(
        new MenuEntryDto('access', 'staff', 'staff_and_permissions', 'test.menu.staff', AccessPermissions::STAFF_VIEW, 10),
        new MenuEntryDto('access', 'roles', 'staff_and_permissions', 'test.menu.roles', AccessPermissions::ROLE_MANAGE, 20),
        new MenuEntryDto('platform', 'media', 'media', 'test.menu.media', PlatformPermissions::MEDIA_UPLOAD),
        new MenuEntryDto('platform', 'audit', 'audit', 'test.menu.audit', PlatformPermissions::AUDIT_VIEW),
        // A module not built yet: its permissions do not exist, so it names none (§2.2).
        new MenuEntryDto('catalog', 'products', 'catalog', 'test.menu.products'),
    );
}

/**
 * Stage 2b, P6. The menu is built from what each person may do (handoff §14). Platform keeps the
 * list; what is offered is never what is allowed - every screen still checks its own permission.
 */
describe('the admin menu', function () {
    it('offers a staff member exactly the one action they hold, and nothing else', function () {
        registerTestMenu();
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        // The owner's own words: someone with a single action sees that action and no other.
        expect(offeredMenu())->toBe(['staff_and_permissions/staff']);
    });

    it('offers a Super Admin everything, the modules not built yet included', function () {
        registerTestMenu();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(offeredMenu())->toBe([
            'catalog/products',
            'staff_and_permissions/staff',
            'staff_and_permissions/roles',
            'media/media',
            'audit/audit',
        ]);
    });

    it('never offers a module not built yet to anyone else, however much they hold', function () {
        registerTestMenu();
        // Managing roles is admin-only, so this one has an admin role holding all four.
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_VIEW, AccessPermissions::ROLE_MANAGE, PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::AUDIT_VIEW]);

        expect(offeredMenu())->not->toContain('catalog/products')
            ->and(offeredMenu())->toHaveCount(4);
    });

    it('offers nothing at all to a guest', function () {
        registerTestMenu();
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(offeredMenu())->toBe([]);
    });

    it('keeps a group out entirely when the person has nothing in it', function () {
        registerTestMenu();
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::AUDIT_VIEW], ['sa']));

        expect(array_keys(app(AdminMenu::class)->forCurrentActor()))->toBe(['audit']);
    });

    it('orders entries by their position, then by key', function () {
        app(AdminMenu::class)->register(
            new MenuEntryDto('access', 'second', 'staff_and_permissions', 'test.b', AccessPermissions::STAFF_VIEW, 20),
            new MenuEntryDto('access', 'first', 'staff_and_permissions', 'test.a', AccessPermissions::STAFF_VIEW, 10),
        );
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        expect(offeredMenu())->toBe(['staff_and_permissions/first', 'staff_and_permissions/second']);
    });

    it('refuses a business area the role editor does not use, an entry twice, and two entries on one route', function (Closure $register, string $message) {
        expect($register)->toThrow(LogicException::class, $message);
    })->with([
        'an area that is not one of the role editor\'s' => [
            fn () => app(AdminMenu::class)->register(new MenuEntryDto('access', 'staff', 'invented_area', 'test.x', AccessPermissions::STAFF_VIEW)),
            'not a business area',
        ],
        'the same entry twice' => [
            function () {
                app(AdminMenu::class)->register(new MenuEntryDto('access', 'staff', 'staff_and_permissions', 'test.one', AccessPermissions::STAFF_VIEW));
                app(AdminMenu::class)->register(new MenuEntryDto('access', 'staff', 'staff_and_permissions', 'test.two', AccessPermissions::STAFF_VIEW));
            },
            'registered twice',
        ],
        'two entries on one route' => [
            function () {
                app(AdminMenu::class)->register(new MenuEntryDto('access', 'one', 'staff_and_permissions', 'test.same', AccessPermissions::STAFF_VIEW));
                app(AdminMenu::class)->register(new MenuEntryDto('access', 'two', 'staff_and_permissions', 'test.same', AccessPermissions::STAFF_VIEW));
            },
            'already has a menu entry',
        ],
    ]);
});
