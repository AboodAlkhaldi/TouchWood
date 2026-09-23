<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\ListRoles\ListRoles;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\ListRoles\RoleSummary;
use Modules\Access\Application\Query\ListStaff\ListStaff;
use Modules\Access\Application\Query\ListStaff\ListStaffHandler;
use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Application\Query\StaffActions\StaffActionsForReader;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Application\Query\ViewStaff\ViewStaff;
use Modules\Access\Application\Query\ViewStaff\ViewStaffHandler;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;

/**
 * Access's staff reads, in the shape the screens want (frontend.md §3.3).
 *
 * It decides nothing. Who appears in the list, how much of each person is shown, and what may be
 * done to them are all Access's answers — an admin seen by somebody who is not a Super Admin
 * arrives with no status and no date, and this only arranges what it is given. What happens here is
 * grouping, translation, and turning a store id into a store's name.
 */
final readonly class StaffPages
{
    /** The section for people who work in more than one store (owner, 2026-09-19). */
    public const string CENTRALIZED = 'centralized';

    /** Admins, in their own short section above the rest. */
    public const string ADMINS = 'admins';

    public function __construct(
        private Application $app,
        private GrantRules $rules,
        private PlatformApi $platform,
        private ViewStaffHandler $view,
        private StaffReader $staff,
        private RoleReader $roles,
        private RoleRepository $personalRoles,
        private InMemoryPermissionCatalog $catalog,
        private StaffActionsForReader $actions,
    ) {}

    /** C1. */
    public function list(ListStaffHandler $handler, ?string $search, ?string $status): StaffListPage
    {
        $page = $handler->handle(new ListStaff($search, $status, perPage: 200));
        $stores = $this->storeNames();

        /** @var array<string, list<StaffRow>> $sections */
        $sections = [];

        foreach ($page->staff as $person) {
            $sections[$this->sectionFor($person)][] = $this->row($person);
        }

        return new StaffListPage(
            $this->arrange($sections, $stores),
            $page->total,
            $search,
            $status,
            array_map(static fn (StaffStatus $each): string => $each->value, StaffStatus::cases()),
            $this->rules->mayInviteStaff(),
        );
    }

    /** C2. */
    public function member(string $staffId): StaffMemberPage
    {
        $person = $this->view->handle(new ViewStaff($staffId));
        $row = $this->staff->member($staffId) ?? [];
        $may = $this->actions->forStaff($staffId);
        $stores = $this->storeNames();

        return new StaffMemberPage(
            id: $person->id,
            name: trim($person->firstName.' '.$person->lastName),
            firstName: $person->firstName,
            lastName: $person->lastName,
            jobTitle: $person->jobTitle,
            email: $person->email,
            phone: $person->phone,
            // Access leaves the status empty for an admin a reader may not see in full. Reaching
            // this screen at all means they may, so it is present - but it is read defensively
            // rather than assumed, because a screen is not the place to learn that the hard way.
            status: ($person->status ?? StaffStatus::Active)->value,
            locale: $this->text($row, 'locale') ?? 'ar',
            dateOfBirth: $this->text($row, 'date_of_birth'),
            country: $this->text($row, 'country'),
            address: $this->text($row, 'address'),
            // One person, one lookup. The list shows initials instead, because resolving a picture
            // for every row would be one call to Platform per person (owner, 2026-09-23).
            avatarUrl: $this->avatarUrl($this->text($row, 'avatar_media_id')),
            isAdmin: $person->isAdmin,
            isSuperAdmin: ($row['is_super_admin'] ?? false) === true,
            roleId: $person->roleId,
            roleName: $this->locale() === 'en' ? $person->roleNameEn : $person->roleNameAr,
            allStores: $person->allStores,
            storeNames: $this->named($person->storeIds, $stores),
            actions: $this->actionsOf($person, $staffId, $stores),
            groups: $this->groups(),
            mayEditProfile: $may->mayEditProfile,
            mayChangeEmail: $may->mayChangeEmail,
            mayChangeRole: $may->mayChangeRole,
            mayDisable: $may->mayDisable,
            mayEnable: $may->mayEnable,
            mayResendInvitation: $may->mayResendInvitation,
            mayCancelInvitation: $may->mayCancelInvitation,
            mayRefresh: $may->mayRefresh,
        );
    }

    /**
     * C6 — one person's role and stores.
     *
     * A role says what somebody may do; the stores say where. Two questions, and this is where the
     * second is answered: a role itself never carries stores, so the same saved role reaches Riyadh
     * for one person and everywhere for another.
     *
     * Only the stores the reader may give away are offered. Nobody hands out reach they do not
     * have, and the list comes from Access rather than from the whole store table.
     */
    public function roleEditor(ListRolesHandler $roles, RoleEditorPermissionsHandler $editor, string $staffId): StaffRolePage
    {
        // Asked before anything is looked up, so somebody who may read the staff list but never
        // hand out a role meets Access's refusal here rather than an editor that refuses on save.
        $this->rules->requireSomewhere(AccessPermissions::STAFF_ASSIGN_ROLE);

        // Then the person, so that somebody this reader may not even see gets no further.
        $person = $this->view->handle(new ViewStaff($staffId));
        $level = $person->isAdmin ? RoleLevel::Admin : RoleLevel::Staff;

        // An admin's role comes from the admin list and a staff member's from the staff list: the
        // level follows the person, and is not something this screen offers to change.
        $saved = array_values(array_filter(
            $roles->handle(new ListRoles),
            static fn (RoleSummary $role): bool => $role->level === $level,
        ));

        // What each offered role holds. Whoever may assign a role may read the role screens
        // (access.md §3.2, amendment 8), and the screen needs this to tell a role that was picked
        // and left alone from one that was edited into a role of this person's own.
        $held = $this->roles->savedRolePermissions();
        $offered = [];

        foreach ($saved as $role) {
            $offered[$role->id] = $held[$role->id] ?? [];
        }

        return new StaffRolePage(
            staffId: $person->id,
            staffName: trim($person->firstName.' '.$person->lastName),
            savedRoles: array_map(fn (RoleSummary $role): RoleRow => new RoleRow(
                $role->id,
                $this->locale() === 'en' ? $role->nameEn : $role->nameAr,
                $role->level->value,
                $role->permissionCount,
                $role->holderCount,
                $role->editable,
                [],
            ), $saved),
            savedPermissions: $offered,
            permissions: $this->offered($editor, $level),
            groups: $this->groups(),
            stores: $this->givableStores(),
            roleId: $person->roleId,
            // A role made for this person alone does not appear in the saved list, and editing a
            // saved one here makes it theirs rather than changing it for everybody (access.md §1.5).
            personal: $person->roleId !== null && $this->roles->savedRole($person->roleId) === null,
            accessLevel: $person->allStores ? AccessLevel::AllStores->value : AccessLevel::SelectedStores->value,
            chosen: $person->roleId === null ? [] : $this->permissionsOf($person->roleId),
            storeIds: $person->storeIds,
            exceptions: $this->staff->exceptionsFor($staffId),
        );
    }

    /**
     * C3 — the invitation form.
     *
     * The inviter needs both the power to invite and the power to hand out a role in the new
     * person's stores (amendment 23). The first is asked here so nobody is shown a form they could
     * never send; the second is asked when the invitation is actually sent, with the stores in hand.
     */
    public function invite(ListRolesHandler $roles, RoleEditorPermissionsHandler $editor): InviteStaffPage
    {
        $this->rules->requireSomewhere(AccessPermissions::STAFF_INVITE);
        $this->rules->requireSomewhere(AccessPermissions::STAFF_ASSIGN_ROLE);

        $unlimited = $this->rules->author()->isUnlimited();
        $saved = $roles->handle(new ListRoles);
        $held = $this->roles->savedRolePermissions();
        $offered = [];

        foreach ($saved as $role) {
            $offered[$role->id] = $held[$role->id] ?? [];
        }

        return new InviteStaffPage(
            savedRoles: array_map(fn (RoleSummary $role): RoleRow => new RoleRow(
                $role->id,
                $this->locale() === 'en' ? $role->nameEn : $role->nameAr,
                $role->level->value,
                $role->permissionCount,
                $role->holderCount,
                $role->editable,
                [],
            ), $saved),
            savedPermissions: $offered,
            permissions: $this->offered($editor, RoleLevel::Staff),
            // Nobody but a Super Admin may bring in an admin, so nobody else is shown the actions
            // an admin's role could hold.
            adminPermissions: $unlimited ? $this->offered($editor, RoleLevel::Admin) : [],
            groups: $this->groups(),
            stores: $this->givableStores(),
            countries: Countries::in($this->locale()),
            maySetAdmin: $unlimited,
            locale: $this->locale(),
        );
    }

    /**
     * Everything that may go in a role of one level, in the language being read.
     *
     * @return list<EditorPermissionRow>
     */
    private function offered(RoleEditorPermissionsHandler $editor, RoleLevel $level): array
    {
        return array_map(function (EditorPermission $permission): EditorPermissionRow {
            $group = $permission->group;

            return new EditorPermissionRow(
                $permission->name,
                $this->locale() === 'en' ? $permission->nameEn : $permission->nameAr,
                $group === null ? '' : $group->value,
                $permission->storeFree,
                $permission->grantable,
            );
        }, $editor->handle(new RoleEditorPermissions($level)));
    }

    /**
     * The stores this reader may give away: the ones where they themselves may assign a role.
     *
     * Access answers with every store for a Super Admin, and with **null for somebody who does not
     * hold the action at all** — which is no stores, not all of them.
     *
     * @return list<StoreOption>
     */
    private function givableStores(): array
    {
        $reach = $this->rules->author()->storesFor(AccessPermissions::STAFF_ASSIGN_ROLE);

        if ($reach === null) {
            return [];
        }

        $stores = $this->storeNames();
        $ids = $reach->isAllStores() ? array_keys($stores) : $reach->storeIds();

        return array_values(array_map(
            static fn (string $id): StoreOption => new StoreOption($id, $stores[$id]),
            array_filter($ids, static fn (string $id): bool => isset($stores[$id])),
        ));
    }

    /**
     * What their role allows, and where each action reaches.
     *
     * An action normally reaches the stores of the assignment. An **exception** is one action given
     * stores of its own (access.md §1.5), and it is marked as one — otherwise an admin cannot tell
     * why somebody can do a single thing in a store the rest of their role never touches.
     *
     * @param  array<string, string>  $stores
     * @return list<StaffActionRow>
     */
    private function actionsOf(StaffSummary $person, string $staffId, array $stores): array
    {
        if ($person->roleId === null) {
            return [];
        }

        $exceptions = $this->staff->exceptionsFor($staffId);
        $rows = [];

        foreach ($this->permissionsOf($person->roleId) as $action) {
            $definition = $this->catalog->definition($action);

            if ($definition === null) {
                // A name no module declares any more grants nothing, and naming it would puzzle.
                continue;
            }

            $group = $definition->group;
            $storeFree = $definition->kind === PermissionKind::Global;
            $own = $exceptions[$action] ?? null;

            $rows[] = new StaffActionRow(
                $action,
                (string) __($definition->labelKey(), [], $this->locale()),
                $group === null ? '' : $group->value,
                $storeFree,
                match (true) {
                    $storeFree => null,
                    $own !== null => $this->named($own, $stores),
                    $person->allStores => null,
                    default => $this->named($person->storeIds, $stores),
                },
                $own !== null,
            );
        }

        return $rows;
    }

    /**
     * A role's actions, whether it is a saved role or one made for this person alone.
     *
     * @return list<string>
     */
    private function permissionsOf(string $roleId): array
    {
        $saved = $this->roles->savedRole($roleId);

        if ($saved !== null) {
            return $saved['permissions'];
        }

        return $this->personalRoles->byId($roleId)?->permissions() ?? [];
    }

    /**
     * Which section somebody belongs in: admins first, then anyone working in more than one store,
     * then each store of their own.
     */
    private function sectionFor(StaffSummary $person): string
    {
        if ($person->isAdmin) {
            return self::ADMINS;
        }

        if ($person->allStores || count($person->storeIds) !== 1) {
            return self::CENTRALIZED;
        }

        return $person->storeIds[0];
    }

    /**
     * The sections in the order they are shown, each named, and empty ones left out entirely.
     *
     * @param  array<string, list<StaffRow>>  $sections
     * @param  array<string, string>  $stores
     * @return list<StaffGroup>
     */
    private function arrange(array $sections, array $stores): array
    {
        $order = [self::ADMINS, self::CENTRALIZED, ...array_keys($stores)];
        $groups = [];

        foreach ($order as $key) {
            if (($sections[$key] ?? []) === []) {
                continue;
            }

            $groups[] = new StaffGroup(
                $key,
                match ($key) {
                    self::ADMINS => (string) __('access::staff.admins', [], $this->locale()),
                    self::CENTRALIZED => (string) __('access::staff.centralized', [], $this->locale()),
                    default => $stores[$key] ?? $key,
                },
                $sections[$key],
            );
        }

        return $groups;
    }

    private function row(StaffSummary $person): StaffRow
    {
        // Access leaves an admin's details empty for a reader who may not see them in full; the
        // screen shows what it is given rather than deciding again (R1).
        $hidden = $person->status === null;

        return new StaffRow(
            $person->id,
            trim($person->firstName.' '.$person->lastName),
            $this->locale() === 'en' ? $person->roleNameEn : $person->roleNameAr,
            $person->isAdmin,
            $hidden ? null : $person->jobTitle,
            $hidden ? null : $person->email,
            $person->status?->value,
            $hidden ? null : $person->joinedAt,
        );
    }

    /**
     * The business areas, in the order the editor and the comparison table show them.
     *
     * @return list<PermissionGroupRow>
     */
    private function groups(): array
    {
        return array_map(
            fn (PermissionGroup $group): PermissionGroupRow => new PermissionGroupRow(
                $group->value,
                (string) __($group->labelKey(), [], $this->locale()),
            ),
            PermissionGroup::cases(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function storeNames(): array
    {
        $stores = [];

        foreach ($this->platform->stores() as $store) {
            $stores[$store->id] = $store->name->in($this->locale());
        }

        return $stores;
    }

    /**
     * Store ids as their names, in the language being read.
     *
     * @param  list<string>  $ids
     * @param  array<string, string>  $stores
     * @return list<string>
     */
    private function named(array $ids, array $stores): array
    {
        return array_values(array_filter(array_map(
            static fn (string $id): ?string => $stores[$id] ?? null,
            $ids,
        )));
    }

    /**
     * Their picture, asked of Platform as any module asks for media. A public image is shown only
     * through its variants, the smaller formats first; nothing at all until they are ready.
     */
    private function avatarUrl(?string $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $thumb = $this->platform->mediaUrls($mediaId)?->variants[MediaSize::Thumb->slug()] ?? null;

        if (! is_array($thumb)) {
            return null;
        }

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $thumb[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
