<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending an application without ticking the store's Company Terms, while it has them published (flags-2026-11.md
 * S3.6, Q2 - owner: yes): refused whatever the page sent. A store with none published asks no tick.
 */
final class CompanyTermsNotAccepted extends B2BError
{
    public function __construct()
    {
        parent::__construct('The Company Terms were not accepted.');
    }

    public function type(): string
    {
        return 'b2b.company_terms_not_accepted';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
