<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Modules\Access\Public\Enums\StaffStatus;
use Shared\Domain\Error\ErrorCategory;

/**
 * An action the staff member's state does not allow (spec §4.3): resending an invitation someone
 * already accepted, disabling someone already disabled.
 */
final class InvalidStaffStatus extends AccessError
{
    public function __construct(public readonly StaffStatus $status)
    {
        parent::__construct("Not possible while the staff member is {$status->value}.");
    }

    public function type(): string
    {
        return 'access.invalid_staff_status';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['status' => $this->status->value];
    }
}
