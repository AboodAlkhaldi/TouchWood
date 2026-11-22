<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CreateRole;

use Modules\Access\Domain\ValueObject\RoleLevel;

/**
 * A new saved role (Access spec §3.2). An admin creates staff roles; only a Super Admin creates
 * admin roles.
 */
final readonly class CreateRole
{
    /**
     * @param  list<string>  $permissions  at least one
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public RoleLevel $level,
        public array $permissions,
    ) {}
}
