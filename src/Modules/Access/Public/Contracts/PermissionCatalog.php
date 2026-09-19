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
}
