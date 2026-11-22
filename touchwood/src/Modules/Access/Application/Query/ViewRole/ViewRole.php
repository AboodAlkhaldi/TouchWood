<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewRole;

/**
 * One saved role with its actions and holders (spec §3.2, amendment 8).
 */
final readonly class ViewRole
{
    public function __construct(
        public string $roleId,
    ) {}
}
