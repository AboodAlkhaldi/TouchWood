<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Approving a company that is still "Other" (b2b.md §1.3, amendment 13(b)): its words are a hint to
 * the reviewer, not a type. Staff correct it to a listed type first — one that exists, or one an
 * admin adds for it — then approve.
 */
final class CompanyTypeNotSet extends B2BError
{
    public function __construct()
    {
        parent::__construct('The company is still "Other": correct it to a listed type before approving it.');
    }

    public function type(): string
    {
        return 'b2b.company_type_not_set';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
