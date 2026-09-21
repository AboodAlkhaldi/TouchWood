<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change the account's state does not allow: blocking one that is blocked, unblocking one that is
 * not, or anything at all on an account that was deleted (spec §1.10, §4.1).
 */
final class InvalidCustomerStatus extends AccessError
{
    public function __construct(public readonly string $change, public readonly string $reason)
    {
        parent::__construct("The account cannot be {$change}: {$reason}.");
    }

    public function type(): string
    {
        return 'access.invalid_customer_status';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
