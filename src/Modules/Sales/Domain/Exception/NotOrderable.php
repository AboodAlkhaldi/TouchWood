<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A line not orderable here: not sold, in its mode, unpriced, outside its minimum and maximum, or not enough stock (§1.1, §1.2).
 */
final class NotOrderable extends SalesError
{
    public function __construct()
    {
        parent::__construct('A line cannot be ordered as it is.');
    }

    public function type(): string
    {
        return 'sales.not_orderable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
