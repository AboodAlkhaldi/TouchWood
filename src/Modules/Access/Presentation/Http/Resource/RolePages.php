<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\ListRoles\ListRoles;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\ListRoles\RoleSummary;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Application\Query\ViewRole\RoleDetail;
use Modules\Access\Application\Query\ViewRole\RoleHolder;
use Modules\Access\Application\Query\ViewRole\ViewRole;
use Modules\Access\Application\Query\ViewRole\ViewRoleHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Access's role reads, in the shape the screens want (frontend.md §3.4).
 *
 * It decides nothing: who may see a role, who may edit it and which holders may be named are all
 * answered by the handlers. What happens here is translation and arrangement — a name in the
 * language being read, an action under its business area, a store shown by its name rather than
 * its id.
 */
final readonly class RolePages
{
    public function __construct(
        private Application $app,
        private Translator $translator,
        private InMemoryPermissionCatalog $catalog,
        private RoleReader $roles,
        private PlatformApi $platform,
        private GrantRules $rules,
    ) {}

    /** D1. */
    public function list(ListRolesHandler $handler): RolesPage
    {
        $actionsByRole = $this->roles->savedRolePermissions();

        $rows = array_map(function (RoleSummary $role) use ($actionsByRole): RoleRow {
            $groups = [];

            foreach ($actionsByRole[$role->id] ?? [] as $action) {
                $group = $this->catalog->definition($action)?->group;

                if ($group !== null) {
                    $groups[$group->value] = $group->value;
                }
            }

            return new RoleRow(
                $role->id,
                $this->locale() === 'en' ? $role->nameEn : $role->nameAr,
                $role->level->value,
                $role->permissionCount,
                $role->holderCount,
                $role->editable,
                array_values($groups),
            );
        }, $handler->handle(new ListRoles));

        return new RolesPage($rows, $this->groups(), $this->mayManage());
    }

    /** D2. */
    public function one(ViewRoleHandler $handler, ListRolesHandler $roles, string $roleId): RolePage
    {
        $role = $handler->handle(new ViewRole($roleId));

        return new RolePage(
            $role->id,
            $this->locale() === 'en' ? $role->nameEn : $role->nameAr,
            $role->nameAr,
            $role->nameEn,
            $role->level->value,
            $this->actions($role),
            $this->groups(),
            $role->holderCount,
            array_map($this->holder(...), $role->holders),
            $role->editable,
            // Somewhere for the holders to go, if this role is deleted while anybody holds it.
            $this->sameLevel($roles, $role),
        );
    }

    /** D3, for a role that does not exist yet. */
    public function editor(RoleEditorPermissionsHandler $handler, RoleLevel $level): RoleEditorPage
    {
        return new RoleEditorPage(
            null,
            '',
            '',
            $level->value,
            $this->offered($handler, $level),
            $this->groups(),
            [],
            0,
        );
    }

    /** D3, for one that does. */
    public function editorFor(ViewRoleHandler $view, RoleEditorPermissionsHandler $handler, string $roleId): RoleEditorPage
    {
        $role = $view->handle(new ViewRole($roleId));

        return new RoleEditorPage(
            $role->id,
            $role->nameAr,
            $role->nameEn,
            $role->level->value,
            $this->offered($handler, $role->level),
            $this->groups(),
            $role->permissions,
            // Said before saving: changing a saved role changes it for everyone holding it.
            $role->holderCount,
        );
    }

    /**
     * Whether this reader may add a role at all.
     *
     * Asked of Access rather than worked out here. Nothing under Presentation/Http checks a
     * permission itself, so that the same rule holds whether the action arrives over HTTP, from the
     * console or from a queued job (access.md §8, and the guard in AccessDecisionsTest).
     */
    public function mayManage(): bool
    {
        return $this->rules->mayManageRoles();
    }

    /**
     * The business areas, in the order the role editor and the comparison table show them.
     *
     * @return list<PermissionGroupRow>
     */
    private function groups(): array
    {
        return array_map(
            fn (PermissionGroup $group): PermissionGroupRow => new PermissionGroupRow(
                $group->value,
                (string) $this->translator->get($group->labelKey(), [], $this->locale()),
            ),
            PermissionGroup::cases(),
        );
    }

    /**
     * @return list<RolePermissionRow>
     */
    private function actions(RoleDetail $role): array
    {
        $rows = [];

        foreach ($role->permissions as $action) {
            $definition = $this->catalog->definition($action);

            if ($definition === null) {
                // A name no module declares any more: it grants nothing, and naming it on a screen
                // would only puzzle somebody (access.md, GrantRules).
                continue;
            }

            $group = $definition->group;

            $rows[] = new RolePermissionRow(
                $action,
                (string) $this->translator->get($definition->labelKey(), [], $this->locale()),
                $group === null ? '' : $group->value,
                $definition->kind === PermissionKind::Global,
            );
        }

        return $rows;
    }

    /**
     * @return list<EditorPermissionRow>
     */
    private function offered(RoleEditorPermissionsHandler $handler, RoleLevel $level): array
    {
        return array_map(
            function (EditorPermission $permission): EditorPermissionRow {
                $group = $permission->group;

                return new EditorPermissionRow(
                    $permission->name,
                    $this->locale() === 'en' ? $permission->nameEn : $permission->nameAr,
                    $group === null ? '' : $group->value,
                    $permission->storeFree,
                    $permission->grantable,
                );
            },
            $handler->handle(new RoleEditorPermissions($level)),
        );
    }

    private function holder(RoleHolder $holder): RoleHolderRow
    {
        $names = null;

        if ($holder->storeIds !== null) {
            $byId = [];

            foreach ($this->platform->stores() as $store) {
                $byId[$store->id] = $store->name->in($this->locale());
            }

            $names = array_values(array_filter(array_map(
                static fn (string $storeId): ?string => $byId[$storeId] ?? null,
                $holder->storeIds,
            )));
        }

        return new RoleHolderRow(
            $holder->staffId,
            trim($holder->firstName.' '.$holder->lastName),
            $names,
        );
    }

    /**
     * Saved roles of the same level, which a holder could be moved to when this one is deleted.
     *
     * @return list<RoleRow>
     */
    private function sameLevel(ListRolesHandler $roles, RoleDetail $role): array
    {
        return array_values(array_filter(
            $this->list($roles)->roles,
            static fn (RoleRow $row): bool => $row->id !== $role->id && $row->level === $role->level->value,
        ));
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
