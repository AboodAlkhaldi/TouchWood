<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Application\Command\ChangeStaffRole\ActionStores;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Command\CreateRole\CreateRole;
use Modules\Access\Application\Command\CreateRole\CreateRoleHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Actor;
use Shared\Application\ActorContext;

/**
 * Staff, roles and assignments for Access tests. Staff rows are written directly: inviting staff
 * arrives with sign-in (step 3). Roles and assignments go through the handlers, as the system.
 */
final class AccessFixtures
{
    public static function staff(StaffStatus $status = StaffStatus::Active, bool $superAdmin = false, string $firstName = 'Staff'): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('access.staff_users')->insert([
            'id' => $id,
            'email' => "{$id}@example.test",
            'first_name' => $firstName,
            'last_name' => 'Member',
            'locale' => 'en',
            'status' => $status->value,
            'is_super_admin' => $superAdmin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * From now on in this test, $actor is the one acting.
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
            app(ChangeStaffRoleHandler::class)->handle(new ChangeStaffRole(
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
                savedRoleId: $roleId,
            ));
        });
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
        $previous = app(ActorContext::class)->current();
        self::actAs(Actor::system());

        try {
            return $work();
        } finally {
            self::actAs($previous);
        }
    }
}
