<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Approving an application whose company type was deactivated since it was sent, without saying
 * which type the approved company carries: the replacement, the old type for this company alone, or
 * a correction (b2b.md §1.3, §7, amendment 10). Nothing is written; the reviewer chooses and approves
 * again.
 */
final class CompanyTypeChoiceRequired extends B2BError
{
    public function __construct()
    {
        parent::__construct('The application\'s company type was deactivated since it was sent: choose which type the company keeps.');
    }

    public function type(): string
    {
        return 'b2b.company_type_choice_required';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
