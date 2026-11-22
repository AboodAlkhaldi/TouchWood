<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Switching a gateway the store does not have yet (§1.2): its first key sets it up.
 */
final class GatewayNotSetUp extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('This store has no payment gateway yet.');
    }

    public function type(): string
    {
        return 'payments.gateway_not_set_up';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
