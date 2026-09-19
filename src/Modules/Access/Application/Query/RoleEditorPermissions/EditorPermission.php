<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\RoleEditorPermissions;

final readonly class EditorPermission
{
    /**
     * @param  bool  $storeFree  its store boxes are shown ticked and disabled (owner, 2026-09-19)
     * @param  bool  $adminOnly  a management action: offered only for admin roles
     * @param  bool  $grantable  whether the author holds it, so may tick it
     * @param  list<string>|null  $authorStoreIds  where the author may give it: null for all stores
     *                                             (or a store-free action), empty when not at all
     */
    public function __construct(
        public string $name,
        public string $nameAr,
        public string $nameEn,
        public bool $storeFree,
        public bool $adminOnly,
        public bool $grantable,
        public ?array $authorStoreIds,
    ) {}
}
