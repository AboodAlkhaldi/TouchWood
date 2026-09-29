<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Approving the waiting application of a company whose account was erased (b2b.md §1.1, amendment
 * 13(e)): nobody can ever sign in to it again, so an approval would decide nothing. Staff reject it.
 */
final class CompanyAccountDeleted extends B2BError
{
    public function __construct()
    {
        parent::__construct('The account behind this company was erased: reject its application instead.');
    }

    public function type(): string
    {
        return 'b2b.company_account_deleted';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
