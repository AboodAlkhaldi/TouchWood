<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewRole;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\ValueObject\RoleLevel;

final readonly class ViewRoleHandler
{
    public function __construct(
        private GrantRules $rules,
        private RoleReader $roles,
        private GrantsReader $grants,
    ) {}

    public function handle(ViewRole $query): RoleDetail
    {
        $this->rules->requireRoleReader();
        $author = $this->rules->author();

        $found = $this->roles->savedRole($query->roleId) ?? throw new RoleNotFound($query->roleId);
        $role = $found['role'];
        $level = RoleLevel::from($role['level']);

        $holders = [];
        $coversEveryone = true;

        foreach ($this->roles->holders($role['id']) as $holder) {
            $grants = $this->grants->forStaff($holder['staff_id']);
            $stores = $grants?->stores;
            $manages = $this->rules->covers($author, $stores) && ($grants?->isAdmin() !== true || $author->isUnlimited());

            if (! $manages) {
                $coversEveryone = false;

                continue;
            }

            $holders[] = new RoleHolder($holder['staff_id'], $holder['first_name'], $holder['last_name'], $stores === null || $stores->isAllStores() ? null : $stores->storeIds());
        }

        return new RoleDetail(
            $role['id'],
            $role['name_ar'],
            $role['name_en'],
            $level,
            $found['permissions'],
            $role['holder_count'],
            $holders,
            $this->rules->mayManageRoles() && $coversEveryone && ($level === RoleLevel::Staff || $author->isUnlimited()),
        );
    }
}
