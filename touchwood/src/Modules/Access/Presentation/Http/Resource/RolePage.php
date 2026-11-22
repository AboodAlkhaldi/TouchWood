<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * D2 - one role: what it allows, and who holds it (frontend.md 3.4).
 */
#[TypeScript]
final class RolePage extends Data
{
    /**
     * @param  list<RolePermissionRow>  $permissions  grouped by business area on the screen
     * @param  list<RoleHolderRow>  $holders  only those the reader manages
     * @param  list<PermissionGroupRow>  $groups
     * @param  list<RoleRow>  $replacements  saved roles of the same level a holder could move to,
     *                                       for the delete that needs one
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $nameAr,
        public string $nameEn,
        public string $level,
        public array $permissions,
        public array $groups,
        public int $holderCount,
        public array $holders,
        public bool $editable,
        public array $replacements,
    ) {}
}
