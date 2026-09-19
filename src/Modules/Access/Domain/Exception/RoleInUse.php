<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a saved role that staff hold, without a replacement for them (owner's decision,
 * 2026-09-19).
 */
final class RoleInUse extends AccessError
{
    /**
     * @param  list<string>  $holderIds  the staff members who hold it
     */
    public function __construct(
        public readonly string $roleId,
        public readonly array $holderIds,
    ) {
        parent::__construct('The role "'.$roleId.'" is held by '.count($holderIds).' staff member(s); pick a replacement role for them.');
    }

    public function type(): string
    {
        return 'access.role_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['count' => count($this->holderIds)];
    }
}
