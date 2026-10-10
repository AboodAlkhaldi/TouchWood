<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Authorization\InvalidPermissionCheck;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\B2B\Application\B2BPermissions;
use Modules\Platform\Infrastructure\Queue\JobActorState;
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
 * menu does. The panel has no store worked in (platform.md §9.10 #2): an entry is offered for a
 * permission held in any store.
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
        // Only what no module registers for real. Staff, roles, stores, currencies, settings,
        // media and audit are all real entries now, and a module may not claim one key twice - so
        // what is left here is this test's own invention, and is named so.
        new MenuEntryDto('access', 'saved-roles', 'staff_and_permissions', 'test.menu.saved-roles', AccessPermissions::ROLE_MANAGE, 20),
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

        // Everything under staff_and_permissions and store_settings but "saved-roles" is a real
        // entry, registered at boot by the module that owns it; the rest are this test's own. The
        // list grows as screens ship, and the order it grows in is the thing being asserted.
        expect(offeredMenu())->toBe([
            'catalog/products',
            // Catalog's shared lists (catalog.md §4.4, step 1 of its screens).
            'catalog/categories',
            'catalog/brands',
            'catalog/attributes',
            'catalog/variations',
            'catalog/labels',
            'catalog/warranties',
            'catalog/search_words',
            // B2B's staff screens (b2b.md amendment 21).
            'companies/companies',
            'companies/company_types',
            'companies/document_types',
            'customers/customers',
            'staff_and_permissions/staff',
            'staff_and_permissions/roles',
            'staff_and_permissions/saved-roles',
            'store_settings/stores',
            'store_settings/currencies',
            'store_settings/settings',
            'store_settings/address-formats',
            'media/media',
            'audit/audit',
            // A Super Admin holds every admin-only action (owner, 2026-09-29).
            'system/failed_jobs',
        ]);
    });

    it('never offers a module not built yet to anyone else, however much they hold', function () {
        registerTestMenu();
        // Managing roles is admin-only, so this one has an admin role holding all four.
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_VIEW, AccessPermissions::ROLE_MANAGE, PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::AUDIT_VIEW]);

        // Three of this test's own, plus Access's real staff and roles entries, which they may
        // use as well.
        expect(offeredMenu())->not->toContain('catalog/products')
            ->and(offeredMenu())->toHaveCount(5);
    });

    it('does not treat a job queued by a person as unlimited', function () {
        registerTestMenu();
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);

        // Inside a job, Platform makes the actor the system on behalf of whoever queued it. Acting
        // for a person is not acting unlimited, so the entries of a module not built yet - the ones
        // no permission can gate - stay hidden.
        $jobs = app(JobActorState::class);
        $jobs->enter(1, Actor::system(Actor::staff($staffId)));

        try {
            expect(offeredMenu())->not->toContain('catalog/products');
        } finally {
            $jobs->leave(1);
        }
    });

    it('does not treat a Super Admin whose account is disabled as unlimited', function () {
        registerTestMenu();
        $superAdminId = Fx::staff(superAdmin: true);
        DB::table('access.staff_users')->where('id', $superAdminId)->update(['status' => StaffStatus::Disabled->value]);
        Fx::actAsStaff($superAdminId);

        // Being a Super Admin is not enough: the account has to be a working one.
        expect(offeredMenu())->toBe([]);
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

    it('refuses an entry whose permission no module declares, the moment the menu is built', function () {
        // Platform cannot check this when the entry is registered: the catalog belongs to Access,
        // which sits above it, and not every provider has booted yet. It is caught instead by the
        // authorizer the first time anyone asks for a menu, which is the first request - including
        // a Super Admin's, who is checked through the same door (review of step 0).
        app(AdminMenu::class)->register(
            new MenuEntryDto('access', 'ghost', 'staff_and_permissions', 'test.ghost', 'access.ghost.view', 10),
        );
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => offeredMenu())->toThrow(InvalidPermissionCheck::class, 'no module declares it');
    });

    it('orders entries by their position, then by key when two share one', function () {
        app(AdminMenu::class)->register(
            new MenuEntryDto('access', 'later', 'staff_and_permissions', 'test.b', AccessPermissions::STAFF_VIEW, 20),
            new MenuEntryDto('access', 'first', 'staff_and_permissions', 'test.a', AccessPermissions::STAFF_VIEW, 10),
            // Two at the same position: the key decides, so a menu never shuffles between requests.
            new MenuEntryDto('access', 'beta', 'staff_and_permissions', 'test.d', AccessPermissions::STAFF_VIEW, 20),
        );
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        // Access's own staff entry sits at 10 as well, and "first" comes before "staff".
        expect(offeredMenu())->toBe([
            'staff_and_permissions/first',
            'staff_and_permissions/staff',
            'staff_and_permissions/beta',
            'staff_and_permissions/later',
        ]);
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
        'a count that is not a MenuCount (frontend.md E7)' => [
            fn () => app(AdminMenu::class)->register(new MenuEntryDto('access', 'counted', 'staff_and_permissions', 'test.counted', AccessPermissions::STAFF_VIEW, count: stdClass::class)),
            'does not implement',
        ],
        'an empty list of permissions, which would guard nothing (b2b.md amendment 23(a))' => [
            fn () => app(AdminMenu::class)->register(new MenuEntryDto('access', 'empty', 'staff_and_permissions', 'test.empty', [])),
            'must hold at least one permission',
        ],
        'a list holding something that is not a permission' => [
            fn () => app(AdminMenu::class)->register(new MenuEntryDto('access', 'blank', 'staff_and_permissions', 'test.blank', [AccessPermissions::STAFF_VIEW, ''])),
            'must hold at least one permission',
        ],
    ]);

    /*
    | An entry may name several permissions, any one of which offers it (owner, 2026-10-03; b2b.md
    | amendment 23(a)): a page that serves several jobs is offered to whoever holds any of them.
    */
    it('offers an entry naming several permissions to whoever holds any one of them', function (string $held) {
        app(AdminMenu::class)->register(
            new MenuEntryDto('access', 'either', 'staff_and_permissions', 'test.either', [AccessPermissions::ROLE_MANAGE, PlatformPermissions::AUDIT_VIEW], 30),
        );
        // Managing roles is admin-only, so an admin role carries the one that is held.
        Fx::actAsAdmin(['sa'], [$held]);

        expect(offeredMenu())->toContain('staff_and_permissions/either');
    })->with([
        'the first' => [AccessPermissions::ROLE_MANAGE],
        'the second' => [PlatformPermissions::AUDIT_VIEW],
    ]);

    it('does not offer an entry naming several permissions to someone holding none of them', function () {
        app(AdminMenu::class)->register(
            new MenuEntryDto('access', 'either', 'staff_and_permissions', 'test.either', [AccessPermissions::ROLE_MANAGE, PlatformPermissions::AUDIT_VIEW], 30),
        );
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        expect(offeredMenu())->not->toContain('staff_and_permissions/either');
    });

    it('offers each of B2B\'s type lists to anyone holding any job on it, and only that list', function (string $job, string $offered, string $notOffered) {
        Fx::actAsStaff(Fx::staffWith([$job], ['sa']));

        expect(offeredMenu())->toContain($offered)
            ->and(offeredMenu())->not->toContain($notOffered);
    })->with([
        'adding company types' => [B2BPermissions::COMPANY_TYPE_CREATE, 'companies/company_types', 'companies/document_types'],
        'renaming company types' => [B2BPermissions::COMPANY_TYPE_UPDATE, 'companies/company_types', 'companies/document_types'],
        'deactivating company types' => [B2BPermissions::COMPANY_TYPE_DEACTIVATE, 'companies/company_types', 'companies/document_types'],
        'moving companies between types' => [B2BPermissions::COMPANY_TRANSFER_TYPE, 'companies/company_types', 'companies/document_types'],
        'adding document types' => [B2BPermissions::DOCUMENT_TYPE_CREATE, 'companies/document_types', 'companies/company_types'],
        'renaming document types' => [B2BPermissions::DOCUMENT_TYPE_UPDATE, 'companies/document_types', 'companies/company_types'],
        'deactivating document types' => [B2BPermissions::DOCUMENT_TYPE_DEACTIVATE, 'companies/document_types', 'companies/company_types'],
    ]);

    it('offers a type list for a job held in one store only: the list then opens on that store', function () {
        // The panel has no store worked in (platform.md §9.10 #2; the owner, 2026-10-06): the list
        // chooses its own store, among those where the job is held (b2b.md amendment 30), so a job
        // held only in Egypt offers it.
        Fx::actAsStaff(Fx::staffWith([B2BPermissions::COMPANY_TYPE_UPDATE], ['eg']));

        expect(offeredMenu())->toContain('companies/company_types')
            ->and(offeredMenu())->not->toContain('companies/document_types');
    });
});
