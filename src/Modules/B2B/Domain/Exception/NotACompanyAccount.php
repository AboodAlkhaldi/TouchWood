<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An individual account reached one of the company's own use cases (b2b.md §3.1, §7). The handler
 * refuses it, not merely a screen that does not offer the link: an account type never changes, so
 * this is a fact about the account. An account Access cannot find is refused the same way
 * (amendment 9(b)).
 */
final class NotACompanyAccount extends B2BError
{
    public function __construct()
    {
        parent::__construct('Only a company account can apply as a company.');
    }

    public function type(): string
    {
        return 'b2b.not_a_company_account';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
