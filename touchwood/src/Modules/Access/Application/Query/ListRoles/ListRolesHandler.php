<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListRoles;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Domain\ValueObject\RoleLevel;

final readonly class ListRolesHandler
{
    public function __construct(
        private GrantRules $rules,
        private RoleReader $roles,
    ) {}

    /**
     * @return list<RoleSummary>
     */
    public function handle(ListRoles $query): array
    {
        $this->rules->requireRoleReader();
        $author = $this->rules->author();
        $mayManage = $this->rules->mayManageRoles();

        $summaries = [];

        foreach ($this->roles->savedRoles() as $row) {
            $level = RoleLevel::from($row['level']);

            $summaries[] = new RoleSummary(
                $row['id'],
                $row['name_ar'],
                $row['name_en'],
                $level,
                $row['permission_count'],
                $row['holder_count'],
                $mayManage && ($level === RoleLevel::Staff || $author->isUnlimited()),
            );
        }

        return $summaries;
    }
}
