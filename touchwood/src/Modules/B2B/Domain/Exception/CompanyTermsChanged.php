<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The Company Terms the form showed are no longer the store's (flags-2026-11.md S3.6; owner, 2026-11-20: "stop and
 * re-tick"): others published since, a new version, or taken down - or published since a form that showed none.
 * Nothing is sent; the form reads them again and clears the tick, so what an application keeps is always what its
 * company was shown. Shaped as Sales's `TermsChanged`.
 */
final class CompanyTermsChanged extends B2BError
{
    public function __construct()
    {
        parent::__construct('The Company Terms changed since the form showed them.');
    }

    public function type(): string
    {
        return 'b2b.company_terms_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
