<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A staff member this actor may not manage: a Super Admin (only by console), an admin (only a
 * Super Admin manages admins), or yourself (owner's decisions, 2026-09-18 and 2026-09-19).
 */
final class StaffNotEditable extends AccessError
{
    public const string SUPER_ADMIN = 'super_admin';

    public const string ADMIN = 'admin';

    public const string YOURSELF = 'yourself';

    /**
     * @param  self::SUPER_ADMIN|self::ADMIN|self::YOURSELF  $reason
     */
    public function __construct(
        public readonly string $staffId,
        public readonly string $reason,
    ) {
        parent::__construct("The staff member \"{$staffId}\" cannot be changed here ({$reason}).");
    }

    public function type(): string
    {
        return 'access.staff_not_editable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
