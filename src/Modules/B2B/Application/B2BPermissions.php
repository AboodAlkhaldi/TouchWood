<?php

declare(strict_types=1);

namespace Modules\B2B\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions B2B's use cases check (b2b.md §3). Declared into Access's catalog at boot.
 *
 * The company's own side (§3.1) is two automatic permissions every customer holds for their own
 * data; the handlers themselves refuse an individual account, since an account type never changes.
 * The staff permissions (§3.2) arrive with step 4.
 */
final class B2BPermissions
{
    /** Start, save and send an application, with its files and answers; read the company (§3.1). */
    public const string APPLY = 'b2b.company.apply';

    /** Change the company's address, in every status (§3.1). */
    public const string UPDATE = 'b2b.company.update';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        // Store-free: a company is valid in every store (§1.1), and its account is the only
        // "scope" either permission has.
        return [
            new PermissionDefinitionDto(self::APPLY, PermissionAudience::EveryCustomer, kind: PermissionKind::Global),
            new PermissionDefinitionDto(self::UPDATE, PermissionAudience::EveryCustomer, kind: PermissionKind::Global),
        ];
    }
}
