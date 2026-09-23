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
use Modules\Platform\Infrastructure\Queue\JobActorState;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
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

it('runs against the three seeded stores these tests name', function () {
    // "Every store ticked one by one" below means these three; a fourth would hollow the tests out.
    expect(DB::table('platform.stores')->orderBy('code')->pluck('code')->all())->toBe(['ae', 'eg', 'sa']);
});

it('is Access\'s authorizer, not Platform\'s interim one', function () {
    expect(app(Authorizer::class))->toBeInstanceOf(RoleAuthorizer::class)
        ->and(class_exists('Modules\Platform\Infrastructure\SystemOnlyAuthorizer'))->toBeFalse();
});

it('lets staff act only through their role, in each action\'s stores', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE, AccessPermissions::CUSTOMER_VIEW], ['sa', 'ae'], exceptions: [AccessPermissions::CUSTOMER_VIEW => ['sa']]));

    expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeTrue()
        ->and(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('ae')))->toBeTrue()
        ->and(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('eg')))->toBeFalse()
        // The exception: this action only in KSA.
        ->and(Fx::allows(AccessPermissions::CUSTOMER_VIEW, Fx::inStore('sa')))->toBeTrue()
        ->and(Fx::allows(AccessPermissions::CUSTOMER_VIEW, Fx::inStore('ae')))->toBeFalse()
        // Not in the role at all.
        ->and(Fx::allows(PlatformPermissions::SETTINGS_UPDATE, Fx::inStore('sa')))->toBeFalse()
        ->and(Fx::storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBe(['ae', 'sa'])
        ->and(Fx::storeCodesWith(AccessPermissions::CUSTOMER_VIEW))->toBe(['sa'])
        ->and(Fx::storeCodesWith(PlatformPermissions::SETTINGS_UPDATE))->toBe([]);
});

it('lets an exception reach beyond the store row', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], exceptions: [PlatformPermissions::STORE_UPDATE => ['sa', 'eg']]));

    expect(Fx::storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBe(['eg', 'sa']);
});

it('passes an "every store" check only with All stores, never with every store ticked one by one', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['sa', 'eg', 'ae']));

    expect(Fx::allows(PlatformPermissions::SETTINGS_UPDATE, Fx::inStore('eg')))->toBeTrue()
        ->and(Fx::allows(PlatformPermissions::SETTINGS_UPDATE, PermissionScope::allStores()))->toBeFalse();

    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['*']));

    expect(Fx::allows(PlatformPermissions::SETTINGS_UPDATE, PermissionScope::allStores()))->toBeTrue()
        ->and(Fx::storeCodesWith(PlatformPermissions::SETTINGS_UPDATE))->toBeNull();
});

it('holds a store-free action in full, whatever the staff member\'s stores', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

    expect(Fx::allows(PlatformPermissions::MEDIA_UPLOAD, PermissionScope::global()))->toBeTrue()
        ->and(Fx::storeCodesWith(PlatformPermissions::MEDIA_UPLOAD))->toBeNull();
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

    expect(Fx::allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeTrue()
        ->and(Fx::allows(PlatformPermissions::SETTINGS_UPDATE, PermissionScope::allStores()))->toBeTrue()
        ->and(Fx::allows(AccessPermissions::CUSTOMER_BLOCK, Fx::inStore('eg')))->toBeTrue()
        ->and(Fx::storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBeNull();
});

it('gives a Super Admin who is not active nothing at all', function (StaffStatus $status) {
    Fx::actAsStaff(Fx::staff($status, superAdmin: true));

    expect(Fx::allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeFalse()
        ->and(Fx::storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBe([]);
})->with([StaffStatus::Invited, StaffStatus::Disabled]);

it('never lets a role hold a reserved permission, even one written into it directly', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']);
    DB::table('access.role_permissions')->insert(['role_id' => Fx::roleOf($staffId), 'permission' => PlatformPermissions::STORE_CREATE]);
    app(GrantsReader::class)->refresh($staffId);
    Fx::actAsStaff($staffId);

    expect(Fx::allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeFalse();
});

it('never lets a staff role hold a management action, even one written into it directly', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']);
    DB::table('access.role_permissions')->insert(['role_id' => Fx::roleOf($staffId), 'permission' => AccessPermissions::STAFF_ASSIGN_ROLE]);
    app(GrantsReader::class)->refresh($staffId);
    Fx::actAsStaff($staffId);

    expect(Fx::allows(AccessPermissions::STAFF_ASSIGN_ROLE, Fx::inStore('sa')))->toBeFalse()
        ->and(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeTrue();
});

it('gives every active staff member the automatic staff permissions, and no role nothing else', function () {
    Fx::actAsStaff(Fx::staff());

    expect(Fx::allows(AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global()))->toBeTrue()
        ->and(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeFalse()
        ->and(Fx::allows(AccessPermissions::SESSION_SIGN_IN, PermissionScope::global()))->toBeFalse();
});

it('gives a staff member who is not active nothing at all', function (StaffStatus $status) {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']);
    // Someone invited has no password yet.
    DB::table('access.staff_users')->where('id', $staffId)->update(['status' => $status->value, 'password' => $status === StaffStatus::Invited ? null : 'hash']);
    app(GrantsReader::class)->refresh($staffId);
    Fx::actAsStaff($staffId);

    expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeFalse()
        ->and(Fx::allows(AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse()
        ->and(Fx::storeCodesWith(PlatformPermissions::STORE_UPDATE))->toBe([]);
})->with([StaffStatus::Invited, StaffStatus::Disabled]);

it('gives a staff id with no account nothing', function () {
    Fx::actAsStaff(strtolower((string) Str::ulid()));

    expect(Fx::allows(AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse();
});

it('gives a guest only the automatic guest permissions', function () {
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

    expect(Fx::allows(AccessPermissions::ACCOUNT_REGISTER, PermissionScope::global()))->toBeTrue()
        ->and(Fx::allows(AccessPermissions::ACCOUNT_UPDATE, PermissionScope::global()))->toBeFalse()
        ->and(Fx::allows(PlatformPermissions::MEDIA_UPLOAD, PermissionScope::global()))->toBeFalse();
});

it('lets a link that proves itself be used signed in or not (amendment 38)', function (Actor $actor, bool $holds) {
    Fx::actAs($actor);

    expect(Fx::allows(AccessPermissions::ACCOUNT_VERIFY_EMAIL, PermissionScope::global()))->toBe($holds);
})->with([
    // The customer clicks their own link in a browser where they are already signed in.
    'the customer themselves' => [fn () => Actor::customer(strtolower((string) Str::ulid())), true],
    'a guest with the link' => [fn () => Actor::guest(strtolower((string) Str::ulid())), true],
    'a staff member' => [fn () => Actor::staff(Fx::staff()), false],
]);

it('lets a customer act for their own account only, and an integration not at all', function (Actor $actor, bool $ownAccount) {
    Fx::actAs($actor);

    expect(Fx::allows(AccessPermissions::ACCOUNT_UPDATE, PermissionScope::global()))->toBe($ownAccount)
        ->and(Fx::allows(AccessPermissions::ACCOUNT_REGISTER, PermissionScope::global()))->toBeFalse()
        ->and(Fx::allows(AccessPermissions::STAFF_INVITE, Fx::inStore('sa')))->toBeFalse()
        ->and(Fx::allows(PlatformPermissions::MEDIA_UPLOAD, PermissionScope::global()))->toBeFalse();
})->with([
    // Their own account's actions (spec §1.5); registering belongs to a guest, and no role to either.
    'a customer' => [fn () => Actor::customer(strtolower((string) Str::ulid())), true],
    'an integration' => [fn () => Actor::integration(strtolower((string) Str::ulid())), false],
]);

it('lets the system act in the console; a web server process with no one signed in is a guest, never the system', function () {
    expect(app(ActorContext::class)->current()->type)->toBe(ActorType::System)
        ->and(Fx::allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeTrue();

    // Tests run in the console; pretend this one serves a web request, as PHP-FPM would.
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $console->setValue(app(), false);
    app()->forgetScopedInstances();

    try {
        expect(app(ActorContext::class)->current()->type)->toBe(ActorType::Guest)
            ->and(Fx::allows(PlatformPermissions::STORE_CREATE, PermissionScope::global()))->toBeFalse()
            ->and(app(Authorizer::class)->storesWith(PlatformPermissions::STORE_UPDATE))->toBe([]);
    } finally {
        $console->setValue(app(), true);
        app()->forgetScopedInstances();
    }
});

it('reads only the cache table once a staff member\'s permissions are warm', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));
    $scope = Fx::inStore('sa');
    Fx::allows(PlatformPermissions::STORE_UPDATE, $scope);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $allowed = Fx::allows(PlatformPermissions::STORE_UPDATE, $scope);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($allowed)->toBeTrue()
        ->and($queries)->not->toBeEmpty();

    foreach ($queries as $query) {
        expect($query['query'])->toContain('"cache"');
        expect($query['query'])->not->toContain('"access"');
    }
});

it('sees a change at once, and keeps the old permissions when the change rolls back', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff($staffId);
    expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('eg')))->toBeFalse();

    $wider = Fx::role([PlatformPermissions::STORE_UPDATE]);

    // The cache version is written inside the change's transaction: a rollback takes it back.
    try {
        DB::transaction(function () use ($staffId, $wider) {
            Fx::assign($staffId, $wider, ['sa', 'eg']);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('eg')))->toBeFalse();

    Fx::assign($staffId, $wider, ['sa', 'eg']);

    expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('eg')))->toBeTrue();
});

it('gives an admin role\'s holder its actions like any role', function () {
    Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_ASSIGN_ROLE], ['sa'], RoleLevel::Admin));

    expect(Fx::allows(AccessPermissions::STAFF_ASSIGN_ROLE, Fx::inStore('sa')))->toBeTrue()
        ->and(Fx::allows(AccessPermissions::STAFF_ASSIGN_ROLE, Fx::inStore('ae')))->toBeFalse();
});

/*
| A queued job acts as the system on behalf of whoever queued it. What that means was made explicit
| on 2026-09-22 (owner), after the admin menu became the first thing to ask "is this actor
| unlimited?" and got a different answer from the one scoping gives.
*/
describe('a job queued by a person', function () {
    function inJobFor(Actor $requester, Closure $work): mixed
    {
        $jobs = app(JobActorState::class);
        $jobs->enter(1, Actor::system($requester));

        try {
            return $work();
        } finally {
            $jobs->leave(1);
        }
    }

    it('sees only the stores of the person who queued it, never every store', function () {
        // The case this protects: a job that lists rows "for the stores this actor may see". Before
        // this, it saw every store, because the actor is the system.
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);

        $stores = inJobFor(Actor::staff($staffId), fn () => app(Authorizer::class)->storesWith(AccessPermissions::STAFF_VIEW));
        $ids = array_map(static fn (StoreId $store): string => $store->value, $stores ?? []);

        // null would mean "every store, and any store opened later" - the answer before this.
        expect($stores)->not->toBeNull()
            ->and($ids)->toBe([Fx::storeId('sa')]);
    });

    it('is unlimited when a Super Admin queued it, and not when anyone else did', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        $limited = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);

        expect(inJobFor(Actor::staff($superAdmin), fn () => app(Authorizer::class)->isUnlimited()))->toBeTrue()
            ->and(inJobFor(Actor::staff($limited), fn () => app(Authorizer::class)->isUnlimited()))->toBeFalse()
            // The system on nobody's behalf - a console command, a scheduled job - still is.
            ->and(Fx::asSystem(fn () => app(Authorizer::class)->isUnlimited()))->toBeTrue();
    });

    it('may still do the work it was queued for, even a reserved action its requester cannot do', function () {
        // Deliberate, and pinned here so it is not "fixed" by accident: the person's permission was
        // checked when they started the action. Generating image variants is reserved to Super
        // Admins, yet every upload queues it - restricting this would stop images being resized for
        // everyone but a Super Admin (docs/CONVENTIONS.md, "Actors").
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);

        $refused = inJobFor(Actor::staff($staffId), function (): bool {
            try {
                app(Authorizer::class)->authorize(PlatformPermissions::MEDIA_VARIANTS_GENERATE, PermissionScope::global());

                return false;
            } catch (Unauthorized) {
                return true;
            }
        });

        expect($refused)->toBeFalse();
    });
});
