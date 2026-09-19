<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a saved role that staff hold, without a replacement for them (owner's decision,
 * 2026-09-19). The message lists the holders (spec §1.5).
 */
final class RoleInUse extends AccessError
{
    /**
     * @param  list<string>  $holders  the names of the staff members who hold it
     */
    public function __construct(
        public readonly string $roleId,
        public readonly array $holders,
    ) {
        parent::__construct('The role "'.$roleId.'" is held by '.count($holders).' staff member(s); pick a replacement role for them.');
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
        return ['count' => count($this->holders), 'holders' => implode(', ', $this->holders)];
    }
}
