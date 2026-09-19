<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Granting more than you hold: a permission you lack, or a store you do not cover for it
 * (owner's decision, 2026-09-18).
 */
final class PermissionEscalation extends AccessError
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("You cannot grant \"{$permission}\" there: you do not hold it in every store it would reach.");
    }

    public function type(): string
    {
        return 'access.permission_escalation';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }

    public function context(): array
    {
        return ['permission' => $this->permission];
    }
}
