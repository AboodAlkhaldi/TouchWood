<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Unknown, or of a store the reader does not cover — the same answer for both.
 */
final class ReturnNotFound extends SalesError
{
    public function __construct()
    {
        parent::__construct('No such return.');
    }

    public function type(): string
    {
        return 'sales.return_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
