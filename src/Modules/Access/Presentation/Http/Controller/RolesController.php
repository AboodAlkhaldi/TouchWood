<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\CloneRole\CloneRole;
use Modules\Access\Application\Command\CloneRole\CloneRoleHandler;
use Modules\Access\Application\Command\CreateRole\CreateRole;
use Modules\Access\Application\Command\CreateRole\CreateRoleHandler;
use Modules\Access\Application\Command\DeleteRole\DeleteRole;
use Modules\Access\Application\Command\DeleteRole\DeleteRoleHandler;
use Modules\Access\Application\Command\RefreshRolePermissions\RefreshRolePermissions;
use Modules\Access\Application\Command\RefreshRolePermissions\RefreshRolePermissionsHandler;
use Modules\Access\Application\Command\UpdateRole\UpdateRole;
use Modules\Access\Application\Command\UpdateRole\UpdateRoleHandler;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\ViewRole\ViewRoleHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Resource\RolePages;
use Shared\Domain\Error\DomainError;

/**
 * The roles screens (frontend.md §3.4, D1–D5).
 *
 * Nothing here decides who may do what: every handler behind these pages asks Access, and Access
 * refuses in its own words. What the screens add is the shape — the actions grouped by business
 * area, the holders a reader may name, and the replacement a delete needs.
 *
 * Only a Super Admin makes or changes an **admin** role. Admins see admin roles in the list and
 * cannot open them; `editable` on each row is Access's answer, not this controller's opinion.
 */
final readonly class RolesController
{
    /**
     * The files these screens read: their own, the words behind a refusal, the names of the actions
     * and their business areas - and the shell's.
     *
     * `admin` and `access::auth` are the layout's: the sidebar says "Sign out" and names the panel.
     * A screen that ships its own words but not its layout's shows the layout's keys raw at a
     * person, which is what "access::auth.sign_out" sitting in the sidebar looked like (found by
     * running it, 2026-09-23).
     *
     * @var list<string>
     */
    private const array WORDS = ['access::roles', 'access::errors', 'access::permissions', 'access::permission_groups', 'access::auth', 'admin'];

    public function __construct(
        private Page $page,
        private RolePages $pages,
    ) {}

    /** D1. */
    public function index(ListRolesHandler $roles): Response
    {
        return $this->page->render('Access/Admin/Roles/Index', $this->pages->list($roles)->toArray(), self::WORDS);
    }

    /** D2. */
    public function show(string $roleId, ViewRoleHandler $role, ListRolesHandler $roles): Response
    {
        return $this->page->render('Access/Admin/Roles/Show', $this->pages->one($role, $roles, $roleId)->toArray(), self::WORDS);
    }

    /** D3, for a role that does not exist yet. */
    public function create(Request $request, RoleEditorPermissionsHandler $editor): Response
    {
        return $this->page->render(
            'Access/Admin/Roles/Edit',
            $this->pages->editor($editor, $this->level($request))->toArray(),
            self::WORDS,
        );
    }

    /** D3, for one that does. */
    public function edit(string $roleId, ViewRoleHandler $role, RoleEditorPermissionsHandler $editor): Response
    {
        return $this->page->render(
            'Access/Admin/Roles/Edit',
            $this->pages->editorFor($role, $editor, $roleId)->toArray(),
            self::WORDS,
        );
    }

    public function store(Request $request, CreateRoleHandler $handler): RedirectResponse
    {
        try {
            $roleId = $handler->handle(new CreateRole(
                $request->string('name_ar')->toString(),
                $request->string('name_en')->toString(),
                $this->level($request),
                $this->permissions($request),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['name_ar', 'name_en', 'level', 'permissions']);
        }

        return redirect("/admin/roles/{$roleId}")->with('status', __('access::roles.created'));
    }

    public function update(Request $request, string $roleId, UpdateRoleHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateRole(
                $roleId,
                $request->string('name_ar')->toString(),
                $request->string('name_en')->toString(),
                $this->permissions($request),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['name_ar', 'name_en', 'permissions']);
        }

        return redirect("/admin/roles/{$roleId}")->with('status', __('access::roles.saved'));
    }

    public function clone(Request $request, string $roleId, CloneRoleHandler $handler): RedirectResponse
    {
        try {
            $copy = $handler->handle(new CloneRole(
                $roleId,
                $request->string('name_ar')->toString(),
                $request->string('name_en')->toString(),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['name_ar', 'name_en']);
        }

        return redirect("/admin/roles/{$copy}")->with('status', __('access::roles.cloned'));
    }

    /**
     * D4. A role nobody holds simply goes; one with holders needs a saved role of the same level to
     * move them to, and Access refuses without one, naming the holders.
     */
    public function destroy(Request $request, string $roleId, DeleteRoleHandler $handler): RedirectResponse
    {
        $replacement = $request->string('replacement')->toString();

        try {
            $handler->handle(new DeleteRole($roleId, $replacement === '' ? null : $replacement));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['replacement']);
        }

        return redirect('/admin/roles')->with('status', __('access::roles.deleted'));
    }

    /** D5. */
    public function refresh(Request $request, string $roleId, RefreshRolePermissionsHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RefreshRolePermissions($roleId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('access::roles.refreshed'));
    }

    private function level(Request $request): RoleLevel
    {
        return $request->string('level')->toString() === RoleLevel::Admin->value
            ? RoleLevel::Admin
            : RoleLevel::Staff;
    }

    /**
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        /** @var list<mixed> $chosen */
        $chosen = $request->array('permissions');

        return array_values(array_filter($chosen, is_string(...)));
    }
}
