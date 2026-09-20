<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Command\ChangeStaffRole\ActionStores;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Command\ChangeStaffRole\PersonalRole;
use Modules\Access\Application\Command\CreateRole\CreateRole;
use Modules\Access\Application\Command\CreateRole\CreateRoleHandler;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomer;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomerHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\StoreContext;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Staff, roles and assignments for Access tests, and the helpers every Access test file shares —
 * kept here, not as global functions, so two test files can never declare the same name.
 *
 * Staff rows are written directly: inviting staff arrives with sign-in (step 3). Roles and
 * assignments go through the handlers, as the system.
 */
final class AccessFixtures
{
    /** The password Fx::customer() registers with. */
    public const string CUSTOMER_PASSWORD = 'a long enough password';

    /**
     * A staff row written directly, complete as an invitation leaves it (and, unless invited, as
     * accepting it leaves it: a password and a verified phone).
     */
    public static function staff(StaffStatus $status = StaffStatus::Active, bool $superAdmin = false, string $firstName = 'Staff'): string
    {
        $id = strtolower((string) Str::ulid());
        $accepted = $status !== StaffStatus::Invited;

        DB::table('access.staff_users')->insert([
            'id' => $id,
            'email' => "{$id}@example.test",
            'password' => $accepted ? Hash::make('a long enough password') : null,
            'first_name' => $firstName,
            'last_name' => 'Member',
            'job_title' => 'Tester',
            'date_of_birth' => '1990-01-01',
            'country' => 'SA',
            'phone' => self::phone(),
            'phone_verified_at' => $accepted ? now() : null,
            'locale' => 'en',
            'status' => $status->value,
            'is_super_admin' => $superAdmin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * A phone number no other staff member in this test has.
     */
    public static function phone(): string
    {
        return '+9665'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Drops the staff email and phone unique indexes for the rest of this test (its transaction
     * rolls the drop back), so a test proves the code refuses a taken value, not the database.
     */
    public static function withoutStaffUniqueIndexes(): void
    {
        DB::statement('DROP INDEX access.staff_users_email_unique');
        DB::statement('DROP INDEX access.staff_users_phone_unique');
    }

    /**
     * Dropped inside the test's transaction, so only the code can refuse a taken email or phone.
     */
    public static function withoutCustomerUniqueIndexes(): void
    {
        DB::statement('DROP INDEX access.customers_email_unique');
        DB::statement('DROP INDEX access.customers_phone_unique');
    }

    /**
     * From now on in this test, $actor is the one acting. Forgets every scoped instance, not only
     * those that depend on the actor: they are rebuilt on next use.
     */
    public static function actAs(Actor $actor): void
    {
        // The Platform wrapper still applies: a queued job acts as the system for this actor.
        app()->scoped(ActorContext::class, fn (): ActorContext => new FixedActorContext($actor));
        app()->forgetScopedInstances();
    }

    public static function actAsStaff(string $staffId): void
    {
        self::actAs(Actor::staff($staffId));
    }

    public static function storeId(string $code): string
    {
        return (string) app(PlatformApi::class)->storeByCode($code)?->id;
    }

    /**
     * Runs the work in a store, as every storefront request does.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public static function inStoreCode(string $code, callable $work): mixed
    {
        return app(StoreContext::class)->runIn(StoreId::fromString(self::storeId($code)), $work);
    }

    /**
     * A customer who registered in that store, as a visitor would: active, email unverified, no
     * phone. It registers as a guest and puts the previous ActorContext back afterwards, so a test
     * that goes on to make HTTP requests is not left acting as that fixed guest.
     */
    public static function customer(string $email = 'sara@example.test', string $storeCode = 'sa', string $accountType = 'individual'): string
    {
        // Registering as a guest, then giving the real ActorContext back: a test that goes on to
        // send requests must read who is acting from the session, not from a fixed stand-in.
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        self::actAs(Actor::guest(strtolower((string) Str::ulid())));

        try {
            return self::inStoreCode($storeCode, fn (): string => app(RegisterCustomerHandler::class)->handle(
                new RegisterCustomer($email, self::CUSTOMER_PASSWORD, 'Sara', 'Ali', $accountType, 'en', true),
            ));
        } finally {
            app()->scoped(ActorContext::class, $previous);
            app()->forgetScopedInstances();
        }
    }

    /**
     * The customer themselves, for their own account's use cases.
     */
    public static function actAsCustomer(string $customerId): void
    {
        self::actAs(Actor::customer($customerId));
    }

    public static function inStore(string $code): PermissionScope
    {
        return PermissionScope::store(StoreId::fromString(self::storeId($code)));
    }

    /**
     * Whether the current actor passes this check.
     */
    public static function allows(string $permission, PermissionScope $scope): bool
    {
        try {
            app(Authorizer::class)->authorize($permission, $scope);

            return true;
        } catch (Unauthorized) {
            return false;
        }
    }

    /**
     * The store codes, sorted, where the current actor holds this permission; null for every store.
     *
     * @return list<string>|null
     */
    public static function storeCodesWith(string $permission): ?array
    {
        $stores = app(Authorizer::class)->storesWith($permission);

        if ($stores === null) {
            return null;
        }

        $codes = array_map(fn (StoreId $store): string => (string) DB::table('platform.stores')->where('id', $store->value)->value('code'), $stores);
        sort($codes);

        return $codes;
    }

    /**
     * Loads a staff member's permissions into the cache, as a check would.
     */
    public static function warmCache(string $staffId): void
    {
        app(GrantsReader::class)->forStaff($staffId);
    }

    /**
     * @return list<string> the role's actions as stored
     */
    public static function rolePermissions(string $roleId): array
    {
        /** @var list<string> */
        return DB::table('access.role_permissions')->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();
    }

    public static function roleOf(string $staffId): string
    {
        return (string) DB::table('access.role_assignments')->where('staff_user_id', $staffId)->value('role_id');
    }

    /**
     * A saved role, created by the system.
     *
     * @param  list<string>  $permissions
     */
    public static function role(array $permissions, RoleLevel $level = RoleLevel::Staff, ?string $nameEn = null): string
    {
        $nameEn ??= 'Role '.Str::random(8);

        return self::asSystem(fn (): string => app(CreateRoleHandler::class)->handle(new CreateRole("دور {$nameEn}", $nameEn, $level, $permissions)));
    }

    /**
     * Gives a staff member a saved role in these stores (codes, or ['*'] for all stores), with
     * exceptions as permission => store codes, as the system.
     *
     * @param  list<string>  $stores
     * @param  array<string, list<string>>  $exceptions
     */
    public static function assign(string $staffId, string $roleId, array $stores, array $exceptions = []): void
    {
        self::asSystem(function () use ($staffId, $roleId, $stores, $exceptions): void {
            app(ChangeStaffRoleHandler::class)->handle(self::change($staffId, $stores, $exceptions, savedRoleId: $roleId));
        });
    }

    /**
     * Gives a staff member a personal role with these actions, as the system.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $stores
     * @return string the personal role's id
     */
    public static function personalRole(string $staffId, array $permissions, array $stores = ['sa'], RoleLevel $level = RoleLevel::Staff): string
    {
        self::asSystem(function () use ($staffId, $permissions, $stores, $level): void {
            app(ChangeStaffRoleHandler::class)->handle(self::change($staffId, $stores, personal: new PersonalRole('دور شخصي', 'Personal role', $permissions, $level)));
        });

        return self::roleOf($staffId);
    }

    /**
     * The command for a staff member's role, from store codes ('*' = all stores).
     *
     * @param  list<string>  $stores
     * @param  array<string, list<string>>  $exceptions  permission => store codes
     */
    public static function change(string $staffId, array $stores, array $exceptions = [], ?string $savedRoleId = null, ?PersonalRole $personal = null): ChangeStaffRole
    {
        return new ChangeStaffRole(
            $staffId,
            $stores === ['*'] ? AccessLevel::AllStores : AccessLevel::SelectedStores,
            $stores === ['*'] ? [] : array_map(self::storeId(...), $stores),
            array_map(
                fn (string $permission, array $codes): ActionStores => new ActionStores(
                    $permission,
                    $codes === ['*'] ? AccessLevel::AllStores : AccessLevel::SelectedStores,
                    $codes === ['*'] ? [] : array_map(self::storeId(...), $codes),
                ),
                array_keys($exceptions),
                $exceptions,
            ),
            $savedRoleId,
            $personal,
        );
    }

    /**
     * An active staff member holding a new saved role with these actions, in these stores.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $stores
     * @param  array<string, list<string>>  $exceptions
     */
    public static function staffWith(array $permissions, array $stores, RoleLevel $level = RoleLevel::Staff, array $exceptions = []): string
    {
        $staffId = self::staff();
        self::assign($staffId, self::role($permissions, $level), $stores, $exceptions);

        return $staffId;
    }

    /**
     * An admin of these stores, acting from now on, who holds these actions there.
     *
     * @param  list<string>  $stores
     * @param  list<string>  $permissions
     * @param  array<string, list<string>>  $exceptions
     */
    public static function actAsAdmin(array $stores, array $permissions, array $exceptions = []): string
    {
        $adminId = self::staffWith($permissions, $stores, RoleLevel::Admin, $exceptions);
        self::actAsStaff($adminId);

        return $adminId;
    }

    /**
     * Permission names from a test dataset, which arrive untyped.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function names(array $values): array
    {
        $names = [];

        foreach ($values as $value) {
            $names[] = is_string($value) ? $value : throw new LogicException('A permission name must be a string.');
        }

        return $names;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function asSystem(callable $work): mixed
    {
        // The binding itself, not the actor it gives: a test using the real ActorContext (HTTP
        // requests) must get it back, not a fixed stand-in.
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        self::actAs(Actor::system());

        try {
            return $work();
        } finally {
            app()->scoped(ActorContext::class, $previous);
            app()->forgetScopedInstances();
        }
    }

    /**
     * How many audit entries have this action and subject.
     */
    public static function audits(string $action, ?string $subjectId = null): int
    {
        return DB::table('platform.audit_entries')->where('action', $action)
            ->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))
            ->count();
    }
}
