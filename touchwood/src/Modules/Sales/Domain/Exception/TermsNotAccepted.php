<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Placing, or paying online, without the terms' box ticked (flags-2026-11.md S3.3): refused whatever the page sent.
 */
final class TermsNotAccepted extends SalesError
{
    public function __construct()
    {
        parent::__construct('The terms were not accepted.');
    }

    public function type(): string
    {
        return 'sales.terms_not_accepted';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
