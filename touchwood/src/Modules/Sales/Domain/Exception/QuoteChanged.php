<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;

/**
 * Anything of §1.3 step 3 no longer as quoted, or another module refusing the placement — everything
 * rolled back, the customer sent to a new quote (§1.3). `reason` says what moved: a rule of the quote
 * (`audience`, `way_to_pay`, `gift`, `ceiling`), something gone since (`store`, `customer`, `lines`,
 * `phone`), or the refusing error's type (`sales.not_orderable`, `promotions.coupon_refused`,
 * `loyalty.redemption_changed`, `inventory.not_enough_stock`, …) — that error kept as the previous one,
 * the sizes it names passed on.
 */
final class QuoteChanged extends SalesError
{
    public function __construct(public readonly string $reason = '', ?DomainError $refusal = null)
    {
        parent::__construct('The checkout changed since it was quoted.'.($reason === '' ? '' : " ({$reason})"), 0, $refusal);
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
        $refusal = $this->getPrevious();
        $variants = $refusal instanceof DomainError ? ($refusal->context()['variants'] ?? null) : null;

        return $variants === null ? ['reason' => $this->reason] : ['reason' => $this->reason, 'variants' => $variants];
    }
}
