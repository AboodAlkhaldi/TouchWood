<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\CancelStaffInvitation\CancelStaffInvitation;
use Modules\Access\Application\Command\CancelStaffInvitation\CancelStaffInvitationHandler;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\ChangeStaffRole\ActionStores;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Command\ChangeStaffRole\PersonalRole;
use Modules\Access\Application\Command\DisableStaff\DisableStaff;
use Modules\Access\Application\Command\DisableStaff\DisableStaffHandler;
use Modules\Access\Application\Command\EnableStaff\EnableStaff;
use Modules\Access\Application\Command\EnableStaff\EnableStaffHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RefreshStaffPermissions\RefreshStaffPermissions;
use Modules\Access\Application\Command\RefreshStaffPermissions\RefreshStaffPermissionsHandler;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitation;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitationHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\ListStaff\ListStaffHandler;
use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\ViewStaff\ViewStaff;
use Modules\Access\Application\Query\ViewStaff\ViewStaffHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Resource\StaffPages;
use Modules\Access\Public\Enums\AccessLevel;
use Shared\Domain\Error\DomainError;

/**
 * The staff screens (frontend.md §3.3, C1–C9).
 *
 * Everything here is shown only to somebody allowed to do it, and every one of those answers is
 * Access's. A person without the permission meets Access's own refusal, in Access's words — the
 * routing never pretends a screen does not exist.
 *
 * A Super Admin is created and removed by console command only, so no screen here offers either.
 */
final readonly class StaffController
{
    /** @var list<string> */
    private const array WORDS = [
        'access::staff', 'access::roles', 'access::errors', 'access::permissions',
        'access::permission_groups', 'access::auth', 'admin',
    ];

    public function __construct(
        private Page $page,
        private StaffPages $pages,
    ) {}

    /** C1. */
    public function index(Request $request, ListStaffHandler $staff): Response
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        return $this->page->render('Access/Admin/Staff/Index', $this->pages->list(
            $staff,
            $search === '' ? null : $search,
            $status === '' ? null : $status,
        )->toArray(), self::WORDS);
    }

    /** C2, with C4–C9 as the buttons on it. */
    public function show(string $staffId): Response
    {
        return $this->page->render('Access/Admin/Staff/Show', $this->pages->member($staffId)->toArray(), self::WORDS);
    }

    /** C4. */
    public function updateProfile(Request $request, string $staffId, UpdateStaffProfileHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateStaffProfile(
                $staffId,
                $request->string('first_name')->toString(),
                $request->string('last_name')->toString(),
                $request->string('job_title')->toString(),
                $request->string('date_of_birth')->toString(),
                $request->string('country')->toString(),
                $this->optional($request, 'address'),
                $request->string('phone')->toString(),
                $this->optional($request, 'avatar_media_id'),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['first_name', 'last_name', 'job_title', 'date_of_birth', 'country', 'address', 'phone']);
        }

        return back()->with('status', __('access::staff.profile_saved'));
    }

    /** C5. The change happens when the link sent to the new address is used. */
    public function changeEmail(Request $request, string $staffId, ChangeStaffEmailHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ChangeStaffEmail($staffId, $request->string('email')->toString()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['email']);
        }

        return back()->with('status', __('access::staff.email_link_sent'));
    }

    /** C3, the form. */
    public function invite(ListRolesHandler $roles, RoleEditorPermissionsHandler $editor): Response
    {
        return $this->page->render(
            'Access/Admin/Staff/Invite',
            $this->pages->invite($roles, $editor)->toArray(),
            self::WORDS,
        );
    }

    /**
     * C3, sending it.
     *
     * Three steps on the screen, one request at the end of them: nothing is written, and nobody
     * exists, until the invitation is actually sent [decided 2026-09-19].
     */
    public function sendInvitation(Request $request, InviteStaffHandler $handler): RedirectResponse
    {
        $fields = [
            'email', 'first_name', 'last_name', 'job_title', 'date_of_birth', 'country', 'address',
            'phone', 'locale', 'saved_role_id', 'permissions', 'access_level', 'store_ids', 'exceptions',
        ];

        $name = trim($request->string('first_name')->toString().' '.$request->string('last_name')->toString());
        $savedRoleId = $this->optional($request, 'saved_role_id');
        $admin = $request->boolean('admin');

        try {
            $staffId = $handler->handle(new InviteStaff(
                $request->string('email')->toString(),
                $request->string('first_name')->toString(),
                $request->string('last_name')->toString(),
                $request->string('job_title')->toString(),
                $request->string('date_of_birth')->toString(),
                $request->string('country')->toString(),
                $this->optional($request, 'address'),
                $request->string('phone')->toString(),
                $request->string('locale')->toString(),
                $this->accessLevel($request->string('access_level')->toString()),
                $this->strings($request, 'store_ids'),
                $this->exceptions($request),
                $savedRoleId,
                $savedRoleId !== null ? null : new PersonalRole(
                    (string) __('access::staff.personal_role_name', ['name' => $name], 'ar'),
                    (string) __('access::staff.personal_role_name', ['name' => $name], 'en'),
                    $this->strings($request, 'permissions'),
                    // Access refuses an admin unless a Super Admin is asking, so the screen's word
                    // for it is only a request.
                    $admin ? RoleLevel::Admin : RoleLevel::Staff,
                ),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, $fields);
        }

        return redirect("/admin/staff/{$staffId}")->with('status', __('access::staff.invitation_sent'));
    }

    /** C6, the screen. */
    public function role(string $staffId, ListRolesHandler $roles, RoleEditorPermissionsHandler $editor): Response
    {
        return $this->page->render(
            'Access/Admin/Staff/Role',
            $this->pages->roleEditor($roles, $editor, $staffId)->toArray(),
            self::WORDS,
        );
    }

    /**
     * C6, saving it.
     *
     * A saved role picked and left alone is given as it is. One the admin edited becomes this
     * person's own role instead — the saved one is not touched, because other people hold it
     * (access.md §1.5). The screen says which it is by sending a role id or a list of actions.
     */
    public function changeRole(Request $request, string $staffId, ChangeStaffRoleHandler $handler, ViewStaffHandler $view): RedirectResponse
    {
        $fields = ['saved_role_id', 'permissions', 'access_level', 'store_ids', 'exceptions'];

        try {
            $person = $view->handle(new ViewStaff($staffId));
            $savedRoleId = $this->optional($request, 'saved_role_id');

            $handler->handle(new ChangeStaffRole(
                $staffId,
                $this->accessLevel($request->string('access_level')->toString()),
                $this->strings($request, 'store_ids'),
                $this->exceptions($request),
                $savedRoleId,
                // Their own role carries their own name, so an admin reading the role list can see
                // at a glance that it belongs to one person.
                $savedRoleId !== null ? null : new PersonalRole(
                    $this->personalRoleName($person, 'ar'),
                    $this->personalRoleName($person, 'en'),
                    $this->strings($request, 'permissions'),
                    // An admin keeps an admin's role. Access refuses one unless a Super Admin asks.
                    $person->isAdmin ? RoleLevel::Admin : RoleLevel::Staff,
                ),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, $fields);
        }

        return redirect("/admin/staff/{$staffId}")->with('status', __('access::staff.role_saved'));
    }

    /** C7. Disabling ends their sessions and trusted browsers at once. */
    public function disable(Request $request, string $staffId, DisableStaffHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DisableStaff($staffId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('access::staff.disabled'));
    }

    /** C7, the other way. */
    public function enable(Request $request, string $staffId, EnableStaffHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new EnableStaff($staffId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('access::staff.enabled'));
    }

    /** C8. A new link, and the old one dies. */
    public function resendInvitation(Request $request, string $staffId, ResendStaffInvitationHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ResendStaffInvitation($staffId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('access::staff.invitation_resent'));
    }

    /** C8, the other way. */
    public function cancelInvitation(Request $request, string $staffId, CancelStaffInvitationHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new CancelStaffInvitation($staffId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect('/admin/staff')->with('status', __('access::staff.invitation_cancelled'));
    }

    /** C9. For an admin who wants a change to take effect at once. */
    public function refresh(Request $request, string $staffId, RefreshStaffPermissionsHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RefreshStaffPermissions($staffId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('access::staff.refreshed'));
    }

    private function optional(Request $request, string $field): ?string
    {
        $value = $request->string($field)->toString();

        return $value === '' ? null : $value;
    }

    private function personalRoleName(StaffSummary $person, string $locale): string
    {
        $name = trim($person->firstName.' '.$person->lastName);

        return (string) __('access::staff.personal_role_name', ['name' => $name], $locale);
    }

    private function accessLevel(string $value): AccessLevel
    {
        return $value === AccessLevel::AllStores->value ? AccessLevel::AllStores : AccessLevel::SelectedStores;
    }

    /**
     * @return list<string>
     */
    private function strings(Request $request, string $field): array
    {
        /** @var list<mixed> $values */
        $values = $request->array($field);

        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * One action given stores of its own, apart from the rest of the role (access.md §1.5).
     *
     * @return list<ActionStores>
     */
    private function exceptions(Request $request): array
    {
        /** @var list<mixed> $rows */
        $rows = $request->array('exceptions');
        $exceptions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $permission = $row['permission'] ?? null;
            $stores = $row['store_ids'] ?? null;

            if (! is_string($permission)) {
                continue;
            }

            $exceptions[] = new ActionStores(
                $permission,
                $this->accessLevel(is_string($row['access_level'] ?? null) ? $row['access_level'] : ''),
                is_array($stores) ? array_values(array_filter($stores, is_string(...))) : [],
            );
        }

        return $exceptions;
    }
}
