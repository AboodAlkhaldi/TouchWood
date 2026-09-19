<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Authorization\InvalidPermissionCheck;
use Modules\Access\Application\Authorization\RoleAuthorizer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function inStore(string $code): PermissionScope
{
    return PermissionScope::store(StoreId::fromString(Fx::storeId($code)));
}

function allows(string $permission, PermissionScope $scope): bool
{
    try {
        app(Authorizer::class)->authorize($permission, $scope);

        return true;
    } catch (Unauthorized) {
        return false;
    }
}

/**
 * @return list<string>|null store codes, sorted; null for every store
 */
function storeCodesWith(string $permission): ?array
{
    $stores = app(Authorizer::class)->storesWith($permission);

    if ($stores === null) {
        return null;
    }

    $codes = array_map(fn (StoreId $store): string => (string) DB::table('platform.stores')->where('id', $store->value)->value('code'), $stores);
    sort($codes);

    return $codes;
}

it('is Access\'s authorizer, not Platform\'s interim one', function () {
    expect(app(Authorizer::class))->toBeInstanceOf(RoleAuthorizer::class)
        ->and(class_exists('Modules\Platform\Infrastructure\SystemOnlyAuthorizer'))->toBeFalse();
});

it('lets staff act only through their role, in each action\'s stores', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE, AccessPermissions::CUSTOMER_VIEW], ['sa', 'ae'], exceptions: [AccessPermissions::CUSTOMER_VIEW => ['sa']]));

    expect(allows(PlatformPermissions::STORE_UPDATE, inStore('sa')))->toBeTrue()
        ->and(allows(PlatformPermissions::STORE_UPDATE, inStore('ae')))->toBeTrue()
        ->and(allows(PlatformPermissions::STORE_UPDATE, inStore('eg')))->toBeFalse()
        // The exception: this action only in KSA.
        ->and(allows(AccessPermissions::CUSTOMER_VIEW, inStore('sa')))->toBeTrue()
        ->and(allows(AccessPermissions::CUSTOMER_VIEW, inStore('ae')))->toBeFalse()
        // Not in the role at all.
        ->and(allows(PlatformPermissions::SETTINGS_UPDATE, inStore('sa')))->toBeFalse()
        ->and(storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBe(['ae', 'sa'])
        ->and(storeCodesWith(AccessPermissions::CUSTOMER_VIEW))->toBe(['sa'])
        ->and(storeCodesWith(PlatformPermissions::SETTINGS_UPDATE))->toBe([]);
});

it('passes an "every store" check only with All stores, never with every store ticked one by one', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['sa', 'eg', 'ae']));

    expect(allows(PlatformPermissions::SETTINGS_UPDATE, inStore('eg')))->toBeTrue()
        ->and(allows(PlatformPermissions::SETTINGS_UPDATE, PermissionScope::allStores()))->toBeFalse();

    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['*']));

    expect(allows(PlatformPermissions::SETTINGS_UPDATE, PermissionScope::allStores()))->toBeTrue()
        ->and(storeCodesWith(PlatformPermissions::SETTINGS_UPDATE))->toBeNull();
});

it('holds a store-free action in full, whatever the staff member\'s stores', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

    expect(allows(PlatformPermissions::MEDIA_UPLOAD, PermissionScope::global()))->toBeTrue()
        ->and(storeCodesWith(PlatformPermissions::MEDIA_UPLOAD))->toBeNull();
});

it('fails loudly when a check does not match the permission\'s kind, or names nothing declared', function (string $permission, PermissionScope $scope) {
    expect(fn () => app(Authorizer::class)->authorize($permission, $scope))->toThrow(InvalidPermissionCheck::class);
})->with([
    'a store-free permission against a store' => [PlatformPermissions::MEDIA_UPLOAD, PermissionScope::store(StoreId::fromString('01j8z3k4m5n6p7q8r9s0t1v2w3'))],
    'a store-free permission against every store' => [PlatformPermissions::MEDIA_UPLOAD, PermissionScope::allStores()],
    'a per-store permission globally' => [PlatformPermissions::STORE_UPDATE, PermissionScope::global()],
    'an undeclared permission' => ['catalog.product.update', PermissionScope::global()],
]);

it('lets a Super Admin do everything, everywhere, reserved permissions included', function () {
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeTrue()
        ->and(allows(PlatformPermissions::SETTINGS_UPDATE, PermissionScope::allStores()))->toBeTrue()
        ->and(allows(AccessPermissions::CUSTOMER_BLOCK, inStore('eg')))->toBeTrue()
        ->and(storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBeNull();
});

it('never lets a role hold a reserved permission, even one written into it directly', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']);
    DB::table('access.role_permissions')->insert([
        'role_id' => DB::table('access.role_assignments')->where('staff_user_id', $staffId)->value('role_id'),
        'permission' => PlatformPermissions::STORE_CREATE,
    ]);
    app(GrantsReader::class)->refresh($staffId);
    Fx::actAsStaff($staffId);

    expect(allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeFalse();
});

it('gives every active staff member the automatic staff permissions, and no role nothing else', function () {
    Fx::actAsStaff(Fx::staff());

    expect(allows(AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global()))->toBeTrue()
        ->and(allows(PlatformPermissions::STORE_UPDATE, inStore('sa')))->toBeFalse()
        ->and(allows(AccessPermissions::SESSION_SIGN_IN, PermissionScope::global()))->toBeFalse();
});

it('gives a staff member who is not active nothing at all', function (StaffStatus $status) {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']);
    DB::table('access.staff_users')->where('id', $staffId)->update(['status' => $status->value]);
    app(GrantsReader::class)->refresh($staffId);
    Fx::actAsStaff($staffId);

    expect(allows(PlatformPermissions::STORE_UPDATE, inStore('sa')))->toBeFalse()
        ->and(allows(AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse()
        ->and(storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBe([]);
})->with([StaffStatus::Invited, StaffStatus::Disabled]);

it('gives a staff id with no account nothing', function () {
    Fx::actAsStaff(strtolower((string) Str::ulid()));

    expect(allows(AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse();
});

it('gives a guest only the automatic guest permissions', function () {
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

    expect(allows(AccessPermissions::ACCOUNT_REGISTER, PermissionScope::global()))->toBeTrue()
        ->and(allows(AccessPermissions::ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse()
        ->and(allows(PlatformPermissions::MEDIA_UPLOAD, PermissionScope::global()))->toBeFalse();
});

it('lets no customer or integration act yet: customer accounts arrive in step 4', function (Actor $actor) {
    Fx::actAs($actor);

    expect(allows(AccessPermissions::ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse()
        ->and(allows(PlatformPermissions::MEDIA_UPLOAD, PermissionScope::global()))->toBeFalse();
})->with([
    'a customer' => [fn () => Actor::customer(strtolower((string) Str::ulid()))],
    'an integration' => [fn () => Actor::integration(strtolower((string) Str::ulid()))],
]);

it('lets the system act in the console, and still refuses it in a web request until staff sign-in', function () {
    expect(allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeTrue();

    // Tests run in the console; pretend this one serves a web request, as PHP-FPM would.
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $console->setValue(app(), false);
    app()->forgetScopedInstances();

    try {
        expect(allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeFalse()
            ->and(app(Authorizer::class)->storesWith(PlatformPermissions::STORE_UPDATE))->toBe([]);
    } finally {
        $console->setValue(app(), true);
        app()->forgetScopedInstances();
    }
});

it('reads only the cache table once a staff member\'s permissions are warm', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff($staffId);
    allows(PlatformPermissions::STORE_UPDATE, inStore('sa'));
    $scope = inStore('sa');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $allowed = allows(PlatformPermissions::STORE_UPDATE, $scope);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($allowed)->toBeTrue()
        ->and($queries)->not->toBeEmpty();

    foreach ($queries as $query) {
        expect($query['query'])->toContain('"cache"');
        expect($query['query'])->not->toContain('"access"');
    }
});

it('sees a change at once: the cached permissions are replaced inside the change\'s transaction', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff($staffId);
    expect(allows(PlatformPermissions::STORE_UPDATE, inStore('eg')))->toBeFalse();

    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa', 'eg']);

    expect(allows(PlatformPermissions::STORE_UPDATE, inStore('eg')))->toBeTrue();
});

it('gives an admin role\'s holder its actions like any role', function () {
    Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_ASSIGN_ROLE], ['sa'], RoleLevel::Admin));

    expect(allows(AccessPermissions::STAFF_ASSIGN_ROLE, inStore('sa')))->toBeTrue()
        ->and(allows(AccessPermissions::STAFF_ASSIGN_ROLE, inStore('ae')))->toBeFalse();
});
