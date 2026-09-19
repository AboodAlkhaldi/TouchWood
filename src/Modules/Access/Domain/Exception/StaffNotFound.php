<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class StaffNotFound extends AccessError
{
    public function __construct(public readonly string $staffId)
    {
        parent::__construct("No staff member has the id \"{$staffId}\".");
    }

    public function type(): string
    {
        return 'access.staff_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
