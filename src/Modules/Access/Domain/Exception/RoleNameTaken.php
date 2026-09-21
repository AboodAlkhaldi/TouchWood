<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Saved role names are unique in each language, ignoring case (owner's decision, 2026-09-19).
 */
final class RoleNameTaken extends AccessError
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("Another saved role is already called \"{$name}\".");
    }

    public function type(): string
    {
        return 'access.role_name_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['name' => $this->name];
    }
}
