<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * D3 - making a role, or changing one (frontend.md 3.4).
 *
 * The actions offered are the ones the author holds, and no others: nobody hands out what they do
 * not have. Stores are not part of a role at all - they are chosen per staff member (3.3 C6).
 */
#[TypeScript]
final class RoleEditorPage extends Data
{
    /**
     * @param  list<EditorPermissionRow>  $permissions  everything that may go in a role of this
     *                                                  level, grouped on the screen by area
     * @param  list<PermissionGroupRow>  $groups
     * @param  list<string>  $chosen  the actions the role holds now; empty for a new one
     * @param  int  $holderCount  said out loud before saving: editing a saved role changes it for
     *                            everyone holding it
     */
    public function __construct(
        public ?string $id,
        public string $nameAr,
        public string $nameEn,
        public string $level,
        public array $permissions,
        public array $groups,
        public array $chosen,
        public int $holderCount,
    ) {}
}
