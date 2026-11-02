<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A session we did not open for this buyer and page, of another store or mode, for another amount,
 * expired or used (payments.md §1.1 rule 7, §7).
 */
final class SessionNotUsable extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('This payment form can no longer be used.');
    }

    public function type(): string
    {
        return 'payments.session_not_usable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
