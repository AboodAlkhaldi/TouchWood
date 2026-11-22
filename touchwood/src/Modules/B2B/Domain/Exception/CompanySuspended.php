<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A suspended company trying to change what staff approved — its name, CR number, tax number, type
 * or documents (b2b.md §1.1, §7). Suspension is a deliberate act by staff, and a new application
 * that sent the company back to PENDING would let a rename undo it. The way back is staff
 * reinstating it.
 */
final class CompanySuspended extends B2BError
{
    public function __construct()
    {
        parent::__construct('A suspended company cannot change its registered details.');
    }

    public function type(): string
    {
        return 'b2b.company_suspended';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
