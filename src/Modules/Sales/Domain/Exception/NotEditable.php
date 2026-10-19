<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Shipped; cancelled, rejected or finished; a line ticked "reduced in the provider" (§1.7).
 */
final class NotEditable extends SalesError
{
    public function __construct()
    {
        parent::__construct('The order can no longer be changed.');
    }

    public function type(): string
    {
        return 'sales.not_editable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
