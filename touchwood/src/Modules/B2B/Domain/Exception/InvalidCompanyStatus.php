<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change the company's status does not allow (b2b.md §4.1): approving or rejecting a company with
 * no application waiting, suspending one already suspended, reinstating one that is not.
 */
final class InvalidCompanyStatus extends B2BError
{
    public function __construct(public readonly string $change = '', public readonly string $status = '')
    {
        parent::__construct("The company cannot be {$change}: it is {$status}.");
    }

    public function type(): string
    {
        return 'b2b.invalid_company_status';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
