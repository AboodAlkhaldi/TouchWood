<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An action given stores of its own that reach outside the staff member's store row: "Where It
 * Reaches" bounds every action (Access amendment 59, owner 2026-10-04).
 */
final class ActionStoresBeyondReach extends AccessError
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("\"{$permission}\" is given stores outside the stores its holder's role reaches.");
    }

    public function type(): string
    {
        return 'access.action_stores_beyond_reach';
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
