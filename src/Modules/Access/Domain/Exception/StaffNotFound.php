<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class StaffNotFound extends AccessError
{
    /**
     * @param  string  $reference  the id or email that matched nobody
     */
    public function __construct(public readonly string $reference)
    {
        parent::__construct("No staff member matches \"{$reference}\".");
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
