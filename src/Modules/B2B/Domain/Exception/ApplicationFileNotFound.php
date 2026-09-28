<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Opening a file that is not one of the account's own applications' (b2b.md §1.4, §7, amendment
 * 9(c)). Answered the same whether or not such a file exists, so asking tells nobody about another
 * company's papers.
 */
final class ApplicationFileNotFound extends B2BError
{
    public function __construct()
    {
        parent::__construct('That file is not one of your applications\'.');
    }

    public function type(): string
    {
        return 'b2b.application_file_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
