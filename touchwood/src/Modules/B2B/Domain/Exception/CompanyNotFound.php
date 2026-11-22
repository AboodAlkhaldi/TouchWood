<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * No company for that account (b2b.md §7): there is none until the first application is sent.
 */
final class CompanyNotFound extends B2BError
{
    public function __construct()
    {
        parent::__construct('This account has no company yet.');
    }

    public function type(): string
    {
        return 'b2b.company_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
