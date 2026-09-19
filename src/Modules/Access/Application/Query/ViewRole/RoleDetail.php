<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewRole;

use Modules\Access\Domain\ValueObject\RoleLevel;

final readonly class RoleDetail
{
    /**
     * @param  list<string>  $permissions
     * @param  int  $holderCount  everyone who holds it
     * @param  list<RoleHolder>  $holders  only those the reader manages (owner, 2026-09-19)
     * @param  bool  $editable  false for an admin role unless the reader is a Super Admin, and when
     *                          some holder is outside the reader's stores
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public RoleLevel $level,
        public array $permissions,
        public int $holderCount,
        public array $holders,
        public bool $editable,
    ) {}
}
