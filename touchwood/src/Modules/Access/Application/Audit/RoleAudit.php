<?php

declare(strict_types=1);

namespace Modules\Access\Application\Audit;

use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for roles and for who holds them. A role holds no personal data, so every value is
 * kept. Roles and assignments belong to no single store, so the entries are global.
 */
final class RoleAudit
{
    private const string ROLE = 'access.role';

    private const string STAFF = 'access.staff_user';

    public static function created(Role $role, ?string $clonedFrom = null): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('name', null, self::name($role->name()))
            ->changed('kind', null, $role->kind()->value)
            ->changed('level', null, $role->level()->value)
            ->changed('permissions', null, $role->permissions());

        if ($role->personalTo() !== null) {
            $changes->changed('personal_to', null, $role->personalTo());
        }

        if ($clonedFrom !== null) {
            $changes->changed('cloned_from', null, $clonedFrom);
        }

        return new AuditEntryDto('access.role.created', self::ROLE, $role->id(), null, $changes);
    }

    /**
     * @param  list<string>  $permissionsBefore
     * @param  list<string>  $changed
     */
    public static function updated(Role $role, RoleName $nameBefore, RoleLevel $levelBefore, array $permissionsBefore, array $changed, string $action = 'access.role.updated'): AuditEntryDto
    {
        $changes = AuditChanges::none();

        if (in_array('name', $changed, true)) {
            $changes->changed('name', self::name($nameBefore), self::name($role->name()));
        }

        if (in_array('level', $changed, true)) {
            $changes->changed('level', $levelBefore->value, $role->level()->value);
        }

        if (in_array('permissions', $changed, true)) {
            $changes->changed('permissions', $permissionsBefore, $role->permissions());
        }

        return new AuditEntryDto($action, self::ROLE, $role->id(), null, $changes);
    }

    /**
     * @param  list<string>  $holdersMoved
     */
    public static function deleted(Role $role, ?string $replacementId, array $holdersMoved): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('name', self::name($role->name()), null)
            ->changed('permissions', $role->permissions(), null);

        if ($replacementId !== null) {
            $changes->changed('holders_moved_to', null, $replacementId)
                ->changed('holders_moved', null, $holdersMoved);
        }

        return new AuditEntryDto('access.role.deleted', self::ROLE, $role->id(), null, $changes);
    }

    /**
     * A module renamed or removed permissions, carried into this role by a migrate (amendment 3).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function permissionsSynced(string $roleId, array $before, array $after): AuditEntryDto
    {
        return new AuditEntryDto('access.role.permissions_synced', self::ROLE, $roleId, null, AuditChanges::none()->changed('permissions', $before, $after));
    }

    /**
     * The same, for a staff member's exceptions.
     *
     * @param  list<string>  $before  the actions that had their own stores
     * @param  list<string>  $after
     */
    public static function exceptionsSynced(string $staffId, array $before, array $after): AuditEntryDto
    {
        return new AuditEntryDto('access.staff_user.exceptions_synced', self::STAFF, $staffId, null, AuditChanges::none()->changed('exceptions', $before, $after));
    }

    /**
     * A staff member's role, store row or exceptions changed.
     */
    public static function assignmentChanged(?RoleAssignment $before, RoleAssignment $after, string $action = 'access.staff_user.role_changed'): AuditEntryDto
    {
        $was = $before === null ? null : self::assignment($before);
        $now = self::assignment($after);
        $changes = AuditChanges::none();

        foreach ($now as $attribute => $value) {
            if ($was === null || $was[$attribute] !== $value) {
                $changes->changed($attribute, $was[$attribute] ?? null, $value);
            }
        }

        return new AuditEntryDto($action, self::STAFF, $after->staffId(), null, $changes);
    }

    /**
     * @return array{role_id: string, access_level: string, stores: list<string>, exceptions: list<string>}
     */
    private static function assignment(RoleAssignment $assignment): array
    {
        $exceptions = [];

        foreach ($assignment->exceptions() as $permission => $stores) {
            $exceptions[] = $permission.': '.($stores->isAllStores() ? 'all stores' : implode(',', $stores->storeIds()));
        }

        return [
            'role_id' => $assignment->roleId(),
            'access_level' => $assignment->stores()->level->value,
            'stores' => $assignment->stores()->storeIds(),
            'exceptions' => $exceptions,
        ];
    }

    /**
     * @return array{ar: string, en: string}
     */
    private static function name(RoleName $name): array
    {
        return ['ar' => $name->ar, 'en' => $name->en];
    }
}
