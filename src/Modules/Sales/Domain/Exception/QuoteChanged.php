<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Anything of §1.3 step 3 no longer as quoted, or another module refusing the placement — everything
 * rolled back, the customer sent to a new quote (§1.3). `reason` says what moved: a rule of the quote
 * (`audience`, `way_to_pay`, `gift`, `ceiling`) or the refusing error's type (`promotions.…`,
 * `loyalty.…`, `inventory.…`, `sales.…`).
 */
final class QuoteChanged extends SalesError
{
    public function __construct(public readonly string $reason = '')
    {
        parent::__construct('The checkout changed since it was quoted.'.($reason === '' ? '' : " ({$reason})"));
    }

    public function type(): string
    {
        return 'sales.quote_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
