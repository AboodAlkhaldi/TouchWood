<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListRoles\ListRoles;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\ListRoles\RoleSummary;
use Modules\Access\Application\Query\MyPermissions\HeldPermission;
use Modules\Access\Application\Query\MyPermissions\MyPermissions;
use Modules\Access\Application\Query\MyPermissions\MyPermissionsHandler;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\ViewRole\RoleHolder;
use Modules\Access\Application\Query\ViewRole\ViewRole;
use Modules\Access\Application\Query\ViewRole\ViewRoleHandler;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

const READING_ADMIN_ACTIONS = [AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD];

describe('the roles list', function () {
    it('shows every saved role, never a personal one; an admin sees admin roles too, but may edit only staff roles', function () {
        $staffRole = Fx::role([PlatformPermissions::STORE_UPDATE], nameEn: 'Support');
        Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin, 'Store admin');
        $personalId = Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE]);
        Fx::actAsAdmin(['*'], READING_ADMIN_ACTIONS);

        $roles = app(ListRolesHandler::class)->handle(new ListRoles);
        $byName = array_column(array_map(fn (RoleSummary $role): array => ['name' => $role->nameEn, 'editable' => $role->editable], $roles), 'editable', 'name');

        expect($byName)->toMatchArray(['Support' => true, 'Store admin' => false])
            ->and(array_column(array_map(fn (RoleSummary $role): array => ['id' => $role->id], $roles), 'id'))->not->toContain($personalId)
            ->and(collect($roles)->firstWhere('id', $staffRole)?->permissionCount)->toBe(1);
    });

    it('is closed to staff who neither manage nor assign roles', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']));

        expect(fn () => app(ListRolesHandler::class)->handle(new ListRoles))->toThrow(Unauthorized::class);
    });

    it('is open to an admin who only assigns roles, read-only', function () {
        Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

        $roles = app(ListRolesHandler::class)->handle(new ListRoles);

        expect(collect($roles)->every(fn (RoleSummary $role): bool => ! $role->editable))->toBeTrue()
            ->and($roles)->not->toBeEmpty();
    });
});

describe('one role', function () {
    it('lists only the holders the admin could reassign, with the total count', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $ksa = Fx::staff(firstName: 'Khalid');
        $ksaAndUae = Fx::staff(firstName: 'Noura');
        Fx::assign($ksa, $roleId, ['sa']);
        Fx::assign($ksaAndUae, $roleId, ['sa', 'ae']);
        Fx::actAsAdmin(['sa'], READING_ADMIN_ACTIONS);

        $role = app(ViewRoleHandler::class)->handle(new ViewRole($roleId));

        expect($role->holderCount)->toBe(2)
            ->and(array_map(fn (RoleHolder $holder): string => $holder->firstName, $role->holders))->toBe(['Khalid'])
            // Held outside the admin's stores, so the admin may not edit it.
            ->and($role->editable)->toBeFalse()
            ->and($role->permissions)->toBe([PlatformPermissions::STORE_UPDATE]);
    });

    it('lists no holder to an admin who manages roles but assigns none', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $roleId, ['sa']);
        Fx::actAsAdmin(['sa'], [AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_UPDATE]);

        $role = app(ViewRoleHandler::class)->handle(new ViewRole($roleId));

        expect($role->holderCount)->toBe(1)
            ->and($role->holders)->toBe([])
            ->and($role->editable)->toBeFalse();
    });

    it('shows no admin to an admin', function () {
        $adminRole = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        Fx::assign(Fx::staff(), $adminRole, ['sa']);
        Fx::actAsAdmin(['*'], READING_ADMIN_ACTIONS);

        $role = app(ViewRoleHandler::class)->handle(new ViewRole($adminRole));

        expect($role->holderCount)->toBe(1)
            ->and($role->holders)->toBe([])
            ->and($role->editable)->toBeFalse();
    });

    it('does not open a personal role or an unknown one', function (Closure $roleId) {
        Fx::actAsAdmin(['*'], READING_ADMIN_ACTIONS);

        expect(fn () => app(ViewRoleHandler::class)->handle(new ViewRole($roleId())))->toThrow(RoleNotFound::class);
    })->with([
        'a personal role' => [fn () => Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE])],
        'an unknown id' => [fn () => '01j8z3k4m5n6p7q8r9s0t1v2w3'],
        'not an id' => [fn () => 'not-an-id'],
    ]);
});

describe('the role editor', function () {
    it('offers a staff role every assignable action except the management ones, marking what the author may give and where', function () {
        Fx::actAsAdmin(['sa', 'ae'], [AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD], [PlatformPermissions::STORE_UPDATE => ['sa']]);

        $items = [];

        foreach (app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions) as $item) {
            $items[$item->name] = $item;
        }

        $offered = fn (string $name): EditorPermission => $items[$name] ?? throw new LogicException("{$name} is not offered");

        expect(array_keys($items))->not->toContain(AccessPermissions::STAFF_INVITE, AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_CREATE, AccessPermissions::ACCOUNT_REGISTER);
        expect(array_keys($items))->toContain(AccessPermissions::STAFF_VIEW, PlatformPermissions::SETTINGS_UPDATE)
            ->and($offered(PlatformPermissions::STORE_UPDATE)->authorStoreIds)->toBe([Fx::storeId('sa')])
            ->and($offered(PlatformPermissions::MEDIA_UPLOAD)->storeFree)->toBeTrue()
            ->and($offered(PlatformPermissions::MEDIA_UPLOAD)->authorStoreIds)->toBeNull()
            ->and($offered(PlatformPermissions::SETTINGS_UPDATE)->grantable)->toBeFalse()
            ->and($offered(PlatformPermissions::SETTINGS_UPDATE)->authorStoreIds)->toBe([])
            ->and($offered(PlatformPermissions::STORE_UPDATE)->nameEn)->toBe(trans('platform::permissions.store.update', [], 'en'))
            ->and($offered(PlatformPermissions::STORE_UPDATE)->nameAr)->toBe(trans('platform::permissions.store.update', [], 'ar'));
    });

    it('offers the management actions only for an admin role, to a Super Admin', function () {
        Fx::actAsAdmin(['*'], READING_ADMIN_ACTIONS);
        expect(fn () => app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions(RoleLevel::Admin)))->toThrow(SuperAdminOnly::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $names = array_map(fn (EditorPermission $item): string => $item->name, app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions(RoleLevel::Admin)));

        expect($names)->toContain(AccessPermissions::STAFF_INVITE, AccessPermissions::ROLE_MANAGE);
    });
});

describe('what may I do', function () {
    it('answers exactly what the checks allow, and where', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        $held = collect(app(MyPermissionsHandler::class)->handle(new MyPermissions))->mapWithKeys(fn (HeldPermission $permission): array => [$permission->name => $permission->storeIds]);

        expect($held->all())->toEqualCanonicalizing([
            PlatformPermissions::STORE_UPDATE => [Fx::storeId('sa')],
            PlatformPermissions::MEDIA_UPLOAD => null,
            AccessPermissions::OWN_ACCOUNT_UPDATE => null,
        ]);
    });

    it('gives a Super Admin everything', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $names = array_map(fn (HeldPermission $permission): string => $permission->name, app(MyPermissionsHandler::class)->handle(new MyPermissions));

        expect($names)->toContain(PlatformPermissions::STORE_CREATE, AccessPermissions::STAFF_INVITE);
    });
});
