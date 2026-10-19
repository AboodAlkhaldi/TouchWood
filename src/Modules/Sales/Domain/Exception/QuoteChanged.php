<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Anything of §1.3 steps 3–5 no longer as quoted; other modules' refusals at placement reach the customer here, with the reason.
 */
final class QuoteChanged extends SalesError
{
    public function __construct()
    {
        parent::__construct('The checkout changed since it was quoted.');
    }

    public function type(): string
    {
        return 'sales.quote_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
