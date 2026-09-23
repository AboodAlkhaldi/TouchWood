<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\RoleEditorPermissions;

use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\PermissionKind;

final readonly class RoleEditorPermissionsHandler
{
    public function __construct(
        private GrantRules $rules,
        private InMemoryPermissionCatalog $catalog,
        private Translator $translator,
    ) {}

    /**
     * @return list<EditorPermission> in the order the modules declared them
     */
    public function handle(RoleEditorPermissions $query): array
    {
        $this->rules->requireRoleReader();
        $author = $this->rules->author();

        if ($query->level === RoleLevel::Admin && ! $author->isUnlimited()) {
            throw new SuperAdminOnly;
        }

        $items = [];

        foreach ($this->catalog->assignable() as $permission) {
            $adminOnly = in_array($permission->name, AccessPermissions::adminOnly(), true);

            if ($adminOnly && $query->level === RoleLevel::Staff) {
                continue;
            }

            $storeFree = $permission->kind === PermissionKind::Global;
            $stores = $author->storesFor($permission->name);

            $items[] = new EditorPermission(
                $permission->name,
                (string) $this->translator->get($permission->labelKey(), [], 'ar'),
                (string) $this->translator->get($permission->labelKey(), [], 'en'),
                $permission->group,
                $storeFree,
                $adminOnly,
                $stores !== null,
                match (true) {
                    $stores === null => [],
                    $storeFree, $stores->isAllStores() => null,
                    default => $stores->storeIds(),
                },
            );
        }

        return $items;
    }
}
