<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The gateway did not answer (§7): UNAVAILABLE, HTTP 503 - try again in a few minutes.
 */
final class GatewayUnreachable extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('The payment gateway did not answer.');
    }

    public function type(): string
    {
        return 'payments.gateway_unreachable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
