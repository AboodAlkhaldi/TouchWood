<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending a draft whose company type staff have stopped offering since it was chosen (owner,
 * 2026-09-27, b2b.md amendment 2): the customer chooses again, a listed type or "Other".
 */
final class CompanyTypeInactive extends B2BError
{
    public function __construct()
    {
        parent::__construct('The chosen company type is no longer offered.');
    }

    public function type(): string
    {
        return 'b2b.company_type_inactive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
