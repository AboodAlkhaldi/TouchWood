<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Outside the window; more pieces than delivered and not already in a return; more than 3 photos (§1.8).
 */
final class ReturnNotAllowed extends SalesError
{
    public function __construct()
    {
        parent::__construct('The return cannot be asked for as it is.');
    }

    public function type(): string
    {
        return 'sales.return_not_allowed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
