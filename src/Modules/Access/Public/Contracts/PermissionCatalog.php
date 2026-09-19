<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\PermissionDefinitionDto;

/**
 * Every permission in the system, declared by the module that checks it (Access spec §1.5, §2.2).
 * A role can hold only declared permissions.
 *
 * Declare in your module's service provider boot(), and put each permission's name in Arabic and
 * English in your translations: `{module}::permissions.{resource}.{action}`.
 */
interface PermissionCatalog
{
    /**
     * @param  string  $module  the declaring module, e.g. "catalog"
     *
     * @throws \LogicException for a malformed name, a name outside the module's prefix, a name
     *                         declared twice, or a reserved permission that is not for roles
     */
    public function declare(string $module, PermissionDefinitionDto ...$permissions): void;

    /**
     * A permission was renamed: at the end of the next `php artisan migrate`, every role and
     * exception holding $from holds $to instead (owner's decision, 2026-09-19). Keep the call
     * until every server has migrated.
     *
     * @throws \LogicException once every module has booted, if $from is still declared, $to is not
     *                         a declared role permission, or $from is renamed twice
     */
    public function renamed(string $module, string $from, string $to): void;

    /**
     * Permissions that no longer exist: at the end of the next `php artisan migrate`, they are taken
     * out of every role, and each change is audited. A name in a role that is neither declared nor
     * removed is left alone and reported, so a module switched off by mistake wipes nothing.
     *
     * @throws \LogicException once every module has booted, if a removed name is still declared
     */
    public function removed(string $module, string ...$names): void;
}
