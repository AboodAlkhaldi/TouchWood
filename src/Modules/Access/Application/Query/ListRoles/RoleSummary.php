<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListRoles;

use Modules\Access\Domain\ValueObject\RoleLevel;

final readonly class RoleSummary
{
    /**
     * @param  bool  $editable  whether the reader may edit, clone or delete it (an admin role only
     *                          by a Super Admin); editing may still be refused for holders outside
     *                          the reader's stores
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public RoleLevel $level,
        public int $permissionCount,
        public int $holderCount,
        public bool $editable,
    ) {}
}
