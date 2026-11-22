<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Placing after 15 minutes or past the prices' end — checkout quotes again (§1.2).
 */
final class QuoteExpired extends SalesError
{
    public function __construct()
    {
        parent::__construct('The checkout expired.');
    }

    public function type(): string
    {
        return 'sales.quote_expired';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
