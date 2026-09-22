<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\MenuEntryDto;

/**
 * The admin menu, built from what each person may do (handoff §14). Every module registers its own
 * entries in its service provider, as it already does for settings and media usages; Platform keeps
 * the list because it sits below everything and can therefore serve everything (owner, 2026-09-22).
 *
 * Platform never reaches into Access to do it: "may this person do X" is asked of the Shared
 * `Authorizer`, which Access implements.
 */
interface AdminMenu
{
    /**
     * Registering checks what Platform can see for itself while providers boot: the group, and that
     * neither the module/key pair nor the route name is taken. It cannot check that the permission
     * is declared - the catalog belongs to Access, above it - nor that the route or label exists,
     * since neither is registered yet. An undeclared permission is refused by the authorizer the
     * first time a menu is built; routes and labels are covered by a test over the real entries.
     *
     * @throws \LogicException for two entries with the same module and key, two entries claiming
     *                         one route, or a group that is not one the role editor uses
     */
    public function register(MenuEntryDto ...$entries): void;

    /**
     * The entries the person acting now may use, in their groups' order and then their own.
     *
     * A "coming soon" entry — one with no permission, for a module not built yet — is returned for
     * a Super Admin only. A group with nothing in it for this person is not returned at all.
     *
     * @return array<string, list<MenuEntryDto>> group => its entries
     */
    public function forCurrentActor(): array;
}
