<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A checkout that is not this buyer's — the same answer as an unknown one (§1.2).
 */
final class QuoteNotFound extends SalesError
{
    public function __construct()
    {
        parent::__construct('No such checkout.');
    }

    public function type(): string
    {
        return 'sales.quote_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
