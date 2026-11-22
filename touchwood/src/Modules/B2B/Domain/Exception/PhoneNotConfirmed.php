<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending an application before the account has a confirmed phone number (b2b.md §1.2, §7,
 * amendment 26(a), owner 2026-10-04): the account's own, whatever its country. Only Send waits for
 * it - the form is filled and saved as ever.
 */
final class PhoneNotConfirmed extends B2BError
{
    public function __construct()
    {
        parent::__construct('Confirm your phone number before you send the application.');
    }

    public function type(): string
    {
        return 'b2b.phone_not_confirmed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
