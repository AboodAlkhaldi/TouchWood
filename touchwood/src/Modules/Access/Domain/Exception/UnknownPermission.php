<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A role was given a permission no module declares, or one no role can hold (an automatic one).
 */
final class UnknownPermission extends AccessError
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("\"{$permission}\" is not a permission a role can hold.");
    }

    public function type(): string
    {
        return 'access.unknown_permission';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['permission' => $this->permission];
    }
}
