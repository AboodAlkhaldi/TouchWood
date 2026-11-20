<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The terms the sign-up form showed are no longer the store's (flags-2026-11.md S3.4; owner, 2026-11-20: "stop and
 * re-tick"): another Terms and Conditions or Privacy Policy published since, a new version of either, or one taken
 * down. No account is made; the form reads the store's policies again and clears the tick, so what an account records
 * is always what its customer was shown. Shaped as Sales's own `TermsChanged`.
 */
final class TermsChanged extends AccessError
{
    public function __construct()
    {
        parent::__construct('The terms changed since the form showed them.');
    }

    public function type(): string
    {
        return 'access.terms_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
