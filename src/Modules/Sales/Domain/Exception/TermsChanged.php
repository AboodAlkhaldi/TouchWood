<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The terms the checkout showed are no longer the store's (flags-2026-11.md S3.3; owner, 2026-11-20: "stop and
 * re-tick"): another Terms and Conditions or Returns policy published since, or a new version of either, or one taken
 * down. Shaped as `QuoteChanged`: nothing is placed or held, and the customer stays on checkout, which reads the
 * store's terms again and clears the tick, so what is recorded is always what the customer was shown.
 */
final class TermsChanged extends SalesError
{
    public function __construct()
    {
        parent::__construct('The terms changed since the checkout showed them.');
    }

    public function type(): string
    {
        return 'sales.terms_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
