<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * MyFatoorah's 401 on a call (payments.md §1.2): the caller marks the key, and says what that means for it.
 */
final class GatewayKeyRefused extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('The payment gateway refused the key.');
    }

    public function type(): string
    {
        return 'payments.gateway_key_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
